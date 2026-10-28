<?php

namespace Tests\Auth4;

use App\Mail\EmailOtp;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Real OTP/ClientSession HTTP boundary and exhaustive no-write gate. */
class ClientCookingTest extends TestCase
{
    protected string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        $coach = DB::table('users')->insertGetId(['name' => 'Synthetic cooking coach', 'role' => 'admin']);
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic cooking client', 'email' => 'cooking-api@example.invalid',
            'email_normalized' => 'cooking-api@example.invalid', 'email_verified_at' => now()]);
        DB::table('clients')->insert(['user_id' => $user, 'coach_user_id' => $coach]);
        Mail::fake();
        $binding = bin2hex(random_bytes(32));
        $auth = app(ClientAuth::class);
        $challenge = $auth->request('login', 'cooking-api@example.invalid', null, $binding, '192.0.2.57');
        preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);
        $proof = $auth->verify($challenge['challenge_id'], $match[1], $binding, '192.0.2.57')['proof'];
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

    private function snapshot(): array
    {
        $result = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            $name = '"'.str_replace('"', '""', $table->tablename).'"';
            $result[$table->tablename] = DB::selectOne("SELECT md5(coalesce(string_agg(to_jsonb(t)::text || t.xmin::text, '' ORDER BY to_jsonb(t)::text), '')) AS hash FROM $name t")->hash;
        }
        return $result;
    }

    public function test_no_assignment_ignores_foreign_selectors_and_requires_an_explicit_week(): void
    {
        $before = $this->snapshot();
        $response = $this->getJson('/api/meal-plan/cooking?week=1&client_id=999999&assignment_id=999999&version_id=999999')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertExactJson(['assignment' => null, 'week' => 1, 'modes' => [
            ['mode' => 1, 'available' => false, 'templates' => []],
            ['mode' => 2, 'available' => false, 'templates' => []],
            ['mode' => 7, 'available' => false, 'templates' => []],
        ]]);
        $this->getJson('/api/meal-plan/cooking')->assertStatus(422)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/meal-plan/cooking/999999/sessions/999999?week=1&mode=7')->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->snapshot(), 'GET must not mutate session metadata or any table/xmin.');
    }

    public function test_head_does_not_change_session_or_database_rows(): void
    {
        $before = $this->snapshot();
        $response = $this->call('HEAD', '/api/meal-plan/cooking?week=1');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->snapshot(), 'HEAD must not mutate sessions or any table/xmin.');
    }

    public function test_missing_client_session_is_denied_without_caching(): void
    {
        $this->withoutCookie('telmanfit_client_session')->getJson('/api/meal-plan/cooking?week=1')
            ->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
    }
}
