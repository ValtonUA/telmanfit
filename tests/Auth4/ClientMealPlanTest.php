<?php

namespace Tests\Auth4;

use App\Mail\EmailOtp;
use App\Models\User;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use App\Support\Auth\AuthSecretCodec;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MealPlans\AssignmentFixture;
use Tests\TestCase;

class ClientMealPlanTest extends TestCase
{
    use AssignmentFixture;

    private int $client;

    private int $user;

    private int $coach;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        $this->coach = DB::table('users')->insertGetId(['name' => 'Синтетичний тренер T5.1', 'role' => 'admin']);
        $this->user = DB::table('users')->insertGetId(['name' => 'Синтетичний клієнт T5.1', 'email' => 't51@example.invalid',
            'email_normalized' => 't51@example.invalid', 'email_verified_at' => now()]);
        $this->client = DB::table('clients')->insertGetId(['user_id' => $this->user, 'coach_user_id' => $this->coach]);
        Mail::fake();
        $binding = bin2hex(random_bytes(32));
        $auth = app(ClientAuth::class);
        $challenge = $auth->request('login', 't51@example.invalid', null, $binding, '192.0.2.51');
        preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);
        $proof = $auth->verify($challenge['challenge_id'], $match[1], $binding, '192.0.2.51')['proof'];
        $request = Request::create('/api/me');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->setName('telmanfit_client_session');
        $request->session()->start();
        $request->session()->put('client_binding', $binding);
        $request->session()->save();
        app()->instance('request', $request);
        app(ClientSession::class)->issue($request, $proof);
        $this->sessionId = $request->session()->getId();
        $this->withCredentials()->withCookie('telmanfit_client_session', $this->sessionId);
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function assignment(int $version, int $start = 0, ?int $end = null, ?int $client = null): int
    {
        return DB::table('client_meal_plan_assignments')->insertGetId([
            'client_id' => $client ?? $this->client, 'meal_plan_version_id' => $version,
            'start_date' => now()->addDays($start)->toDateString(),
            'end_date' => $end === null ? null : now()->addDays($end)->toDateString(),
        ]);
    }

    /** Повні рядки та MVCC revisions усіх public tables, включно з sessions/cache/audit. */
    private function snapshot(): array
    {
        $hashes = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            $name = '"'.str_replace('"', '""', $table->tablename).'"';
            $hashes[$table->tablename] = DB::selectOne("SELECT md5(coalesce(string_agg(to_jsonb(t)::text || t.xmin::text, '' ORDER BY to_jsonb(t)::text), '')) AS hash FROM $name t")->hash;
        }

        return $hashes;
    }

    private function read(?int $assignment, int $status = 200, string $query = ''): void
    {
        $before = $this->snapshot();
        // Навіть lottery=100% не дає GET права видаляти expired sessions.
        config(['session.lottery' => [100, 100]]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/meal-plan'.$query)->assertStatus($status)
            ->assertHeader('Cache-Control', 'no-store, private');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter(array_column($queries, 'query'),
            fn ($sql) => preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $sql))));
        if ($status === 200) {
            if ($assignment === null) {
                $response->assertExactJson(['assignment' => null]);
            } else {
                $response->assertJsonPath('assignment.id', (string) $assignment)
                    ->assertJsonPath('assignment.meal_plan_version.status', 'published');
            }
        }
        $this->assertSame($before, $this->snapshot(), 'GET не повинен змінювати жодну таблицю або xmin.');
    }

    public static function periods(): array
    {
        return [
            'початок сьогодні' => [0, null, true],
            'кінець сьогодні' => [-3, 0, true],
            'одноденний' => [0, 0, true],
            'відкритий давній' => [-30, null, true],
            'майбутній' => [1, null, false],
            'завершений' => [-3, -1, false],
        ];
    }

    #[DataProvider('periods')]
    public function test_inclusive_dates_and_empty_periods(int $start, ?int $end, bool $active): void
    {
        $assignment = $this->assignment($this->assignmentVersion(), $start, $end);
        $this->read($active ? $assignment : null);
    }

    public function test_no_assignment_and_foreign_selectors_cannot_read_other_clients(): void
    {
        $other = DB::table('clients')->insertGetId(['coach_user_id' => $this->coach]);
        $version = $this->assignmentVersion($other);
        $id = $this->assignment($version, client: $other);
        $this->read(null, query: "?client_id=$other&assignment_id=$id&meal_plan_version_id=$version");
        $own = $this->assignment($this->assignmentVersion());
        $this->read($own, query: "?client_id=$other&assignment_id=$id");
        $this->getJson('/api/meal-plan/'.$id)->assertNotFound();
    }

    public function test_future_replacement_keeps_old_assignment_until_its_inclusive_end(): void
    {
        $old = $this->assignment($this->assignmentVersion(), -3, 0);
        $new = $this->assignment($this->assignmentVersion(), 1);
        DB::table('client_meal_plan_assignments')->where('id', $old)->update(['replaced_by_assignment_id' => $new]);
        $this->read($old);
        $this->travelTo(now()->addDay());
        // Синтетична чинна idle session для наступного календарного дня.
        DB::table('sessions')->where('id', $this->sessionId)->update(['last_activity' => now()->timestamp]);
        Auth::forgetGuards();
        $this->read($new);
    }

    public function test_exact_version_survives_a_newer_publication_and_draft(): void
    {
        $version = $this->assignmentVersion();
        $id = $this->assignment($version);
        $plan = DB::table('meal_plan_versions')->where('id', $version)->value('meal_plan_id');
        // Створюємо повну валідну нову версію, а не published root без дочірнього вмісту.
        $new = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        foreach (DB::table('meal_plan_days')->where('meal_plan_version_id', $version)->get() as $day) {
            $copy = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $new, 'day_number' => $day->day_number]);
            foreach (DB::table('meals')->where('meal_plan_day_id', $day->id)->get() as $meal) {
                $mealCopy = DB::table('meals')->insertGetId(['meal_plan_day_id' => $copy, 'meal_type_id' => $meal->meal_type_id]);
                foreach (DB::table('meal_items')->where('meal_id', $meal->id)->get() as $item) {
                    $data = (array) $item;
                    unset($data['id']);
                    DB::table('meal_items')->insert(array_replace($data, ['meal_id' => $mealCopy]));
                }
            }
        }
        DB::table('meal_plan_versions')->where('id', $new)->update(['status' => 'published', 'version_number' => 2, 'published_at' => now()]);
        DB::table('meal_plan_versions')->insert(['meal_plan_id' => $plan]);
        $this->read($id);
        $this->getJson('/api/meal-plan')->assertJsonPath('assignment.meal_plan_version.id', (string) $version)
            ->assertJsonPath('assignment.meal_plan_version.version_number', 1);
    }

    public function test_draft_assignment_is_rejected_by_postgresql_and_never_exposed(): void
    {
        $version = $this->assignmentVersion(status: 'draft');
        try {
            DB::transaction(fn () => $this->assignment($version));
            $this->fail('Призначення draft має бути відхилене PostgreSQL.');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
        $this->read(null);
    }

    public function test_personal_plan_owner_is_rechecked_even_for_a_raw_foreign_assignment(): void
    {
        $own = $this->assignment($this->assignmentVersion($this->client), -3, 0);
        $this->read($own);
        // У fixture відтворюється історична некоректна прив'язка; API fail closed.
        $other = DB::table('clients')->insertGetId(['coach_user_id' => $this->coach]);
        DB::table('client_meal_plan_assignments')->where('id', $own)->delete();
        $this->assignment($this->assignmentVersion($other));
        $this->read(null);
    }

    public static function invalidSessions(): array
    {
        return array_map(fn ($reason) => [$reason], ['revoked', 'version', 'binding', 'absolute', 'idle', 'role', 'unverified', 'missing_client', 'missing_state']);
    }

    #[DataProvider('invalidSessions')]
    public function test_invalid_sessions_fail_closed_without_writes(string $reason): void
    {
        $this->assignment($this->assignmentVersion());
        match ($reason) {
            'revoked' => DB::table('auth_session_states')->where('session_id', $this->sessionId)->update(['revoked_at' => DB::raw('clock_timestamp()')]),
            'version' => DB::table('users')->where('id', $this->user)->increment('auth_version'),
            'binding' => $this->wrongBinding(),
            'absolute' => $this->expiredState(),
            'idle' => DB::table('sessions')->where('id', $this->sessionId)->update(['last_activity' => time() - 43201]),
            'role' => DB::table('users')->where('id', $this->user)->update(['role' => 'admin']),
            'unverified' => DB::table('users')->where('id', $this->user)->update(['email_verified_at' => null]),
            'missing_client' => DB::table('clients')->where('id', $this->client)->update(['user_id' => null]),
            'missing_state' => DB::table('auth_session_states')->where('session_id', $this->sessionId)->delete(),
        };
        Auth::forgetGuards();
        $this->read(null, 401);
    }

    private function wrongBinding(): void
    {
        app('session')->driver()->put('client_binding', 'other');
        app('session')->driver()->save();
    }

    private function expiredState(): void
    {
        $state = (array) DB::table('auth_session_states')->where('session_id', $this->sessionId)->first();
        DB::table('auth_session_states')->where('session_id', $this->sessionId)->delete();
        foreach (['primary_verified_at', 'absolute_expires_at'] as $key) {
            $state[$key] = CarbonImmutable::parse($state[$key])->subDays(8)->format('Y-m-d H:i:s.uP');
        }
        DB::table('auth_session_states')->insert($state);
    }

    public function test_mfa_requires_verified_session_assurance(): void
    {
        $id = $this->assignment($this->assignmentVersion());
        DB::table('user_totp_factors')->insert(['user_id' => $this->user,
            'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp('JBSWY3DPEHPK3PXP'), 'key_version' => 'test_v1',
            'confirmed_at' => now(), 'created_at' => now()->subMinute()]);
        $this->read(null, 401);
        // Синтетичне вже перевірене assurance, без нового способу автентифікації.
        DB::table('auth_session_states')->where('session_id', $this->sessionId)->update(['mfa_verified_at' => DB::raw('clock_timestamp()'), 'mfa_method' => 'totp']);
        $this->read($id);
        DB::table('auth_session_states')->where('session_id', $this->sessionId)->update(['mfa_method' => 'recovery_code']);
        $this->read($id);
    }

    public function test_boundary_keeps_auth_controlled_and_profile_in_existing_me_endpoint(): void
    {
        $this->getJson('/api/me')->assertOk()->assertJsonPath('client.id', (string) $this->client);
        config(['auth4.enabled' => false]);
        $this->read(null, 404);
        config(['auth4.enabled' => true]);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        $this->read(null, 503);
    }

    public function test_anonymous_and_admin_context_cannot_substitute_client_session(): void
    {
        $this->assignment($this->assignmentVersion());
        $this->defaultCookies = [];
        Auth::forgetGuards();
        $this->read(null, 401);
        $this->actingAs(User::findOrFail($this->coach), 'admin');
        $this->read(null, 401);
    }

    public function test_current_assignment_does_not_require_active_coaching_status(): void
    {
        $id = $this->assignment($this->assignmentVersion());
        foreach (['pending', 'active', 'paused', 'finished'] as $status) {
            DB::table('clients')->where('id', $this->client)->update(['status' => $status, 'coaching_started_at' => now()]);
            $this->read($id);
        }
    }

    public function test_head_is_read_only_too_and_write_methods_are_unavailable(): void
    {
        $this->assignment($this->assignmentVersion());
        $before = $this->snapshot();
        $this->head('/api/meal-plan')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/api/meal-plan')->assertStatus(405);
    }

    public function test_gap_between_periods_has_no_fallback_to_past_or_future_plan(): void
    {
        $this->assignment($this->assignmentVersion(), -3, -1);
        $this->assignment($this->assignmentVersion(), 1);
        $this->read(null);
    }

    public function test_calendar_uses_the_same_server_timezone_as_dec4a(): void
    {
        $version = $this->assignmentVersion();
        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $timezone) {
            config(['app.timezone' => $timezone]);
            $date = now($timezone)->toDateString();
            $id = DB::table('client_meal_plan_assignments')->insertGetId([
                'client_id' => $this->client, 'meal_plan_version_id' => $version,
                'start_date' => $date, 'end_date' => $date,
            ]);
            $this->read($id);
            DB::table('client_meal_plan_assignments')->where('id', $id)->delete();
        }
    }
}
