<?php

namespace Tests\Auth4;

use App\Mail\EmailOtp;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use App\Services\MealPlans\ClientMealPlanDays;
use App\Support\Auth\AuthSecretCodec;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MealPlans\ClientMenuFixture;
use Tests\Recipes\InheritedUomCases;
use Tests\TestCase;

class ClientMealItemRecipeTest extends TestCase
{
    use ClientMenuFixture;

    protected int $client;

    protected int $user;

    protected int $coach;

    protected string $sessionId;

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

    protected function assignment(int $version, int $start = 0, ?int $end = null, ?int $client = null): int
    {
        return DB::table('client_meal_plan_assignments')->insertGetId([
            'client_id' => $client ?? $this->client, 'meal_plan_version_id' => $version,
            'start_date' => now()->addDays($start)->toDateString(),
            'end_date' => $end === null ? null : now()->addDays($end)->toDateString(),
        ]);
    }

    /** Повні рядки та MVCC revisions усіх public tables, включно з sessions/cache/audit. */
    protected function snapshot(): array
    {
        $hashes = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            $name = '"'.str_replace('"', '""', $table->tablename).'"';
            $hashes[$table->tablename] = DB::selectOne("SELECT md5(coalesce(string_agg(to_jsonb(t)::text || t.xmin::text, '' ORDER BY to_jsonb(t)::text), '')) AS hash FROM $name t")->hash;
        }

        return $hashes;
    }

    private function fixture(): array
    {
        $f = $this->menuVersion(14);
        $assignment = $this->assignment($f['version']);
        $menu = app(ClientMealPlanDays::class)->forClient($this->client, 1);

        return $f + ['assignment' => $assignment, 'items' => $menu['days'][0]['meals'][0]['items']];
    }

    private function url(string|int $id): string
    {
        return '/api/meal-plan/items/'.$id.'/recipe';
    }

    private function unchanged(string $url, int $status = 200): TestResponse
    {
        config(['session.lottery' => [100, 100]]);
        $before = $this->snapshot();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson($url)->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter(array_column($queries, 'query'), fn ($sql) => preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $sql))));
        $this->assertSame($before, $this->snapshot());
        $this->head($url)->assertStatus($status);
        $this->assertSame($before, $this->snapshot());

        return $response;
    }

    public function test_physical_legacy_a_b_c3_c4_and_fractional_consumption(): void
    {
        $f = $this->fixture();
        $expected = [[null, '4.00', '150.00', '0.50'], ['A/1', '1', '150.00', '1.25'], ['B/7', '7', '1050.00', '1.25'], ['C/3', '3', '525.00', '1.25'], ['C/4', '4', '525.00', '1.25']];
        foreach ($expected as $i => [$format, $servings, $grams, $assigned]) {
            $r = $this->unchanged($this->url($f['items'][$i + 1]['id']))->assertJsonPath('format', $format)
                ->assertJsonPath('preparation_servings', $servings)->assertJsonPath('assigned_servings', $assigned)
                ->assertJsonPath('ingredients.0.quantity_grams', $grams);
            if ($format === null) {
                $r->assertJsonPath('directions.0.text', 'Взяти 150.00 g, 1.00 шт.. 20 хв, 180°C.');
            }
        }
        $this->unchanged($this->url($f['items'][0]['id']), 404)->assertJsonMissingPath('title');
        $this->postJson($this->url($f['items'][1]['id']))->assertStatus(405);
    }

    public function test_frozen_quantities_tokens_and_equipment_overrides(): void
    {
        // Створюємо склад до publish; жодних змін історичної published-версії.
        $b = $this->batch();
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Синтетична каструля']);
        $row = DB::table('recipe_kitchen_equipment')->insertGetId(['recipe_version_id' => $b['version'], 'kitchen_equipment_id' => $tool]);
        foreach ($b['formats'] as $code => $format) {
            DB::table('recipe_batch_equipment')->insert(['recipe_version_id' => $b['version'], 'format_id' => $format,
                'recipe_kitchen_equipment_id' => $row, 'mode' => 'manual', 'min_value' => $code === 'week' ? '2.50' : null,
                'uom_id' => null, 'tip' => $code.' вручну', 'input_snapshot' => '{"schema_version":1}', 'manual_context' => '{}']);
        }
        DB::table('recipe_directions')->insert(['recipe_version_id' => $b['version'], 'direction_number' => 1,
            'direction_text' => '{{ingredient:'.$b['row'].':quantity}} / {{ingredient:'.$b['row'].':grams}}; {{equipment:'.$row.':name}} {{equipment:'.$row.':quantity}} {{equipment:'.$row.':tip}}. 20 хв, 180°C.']);
        // Та сама adequacy процедура synthetic publication, що у batch fixture.
        DB::update("UPDATE recipe_batch_equipment SET input_snapshot=jsonb_set(input_snapshot,'{adequacy_ack}',to_jsonb(md5(recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id)::text))), manual_context=recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id), review_ack_hash=md5(recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id)::text) WHERE recipe_version_id=?", [$b['version']]);
        $this->publish($b['version']);
        $f = $this->fixture();
        // Окремий draft план із точним published recipe, через штатний fixture hierarchy.
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Тест frozen']);
        $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $type = DB::table('meal_types')->first()->id;
        $serving = DB::table('uoms')->where('code', 'serving')->value('id');
        $ids = [];
        foreach (range(1, 7) as $n) {
            $day = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $n]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $day, 'meal_type_id' => $type]);
            foreach ([['single', 1], ['week', 7], ['half_week', 3], ['half_week', 4]] as [$code, $divisor]) {
                $ids[$n][$code.$divisor] = DB::table('meal_items')->insertGetId(['meal_id' => $meal, 'food_id' => DB::table('recipe_versions')->where('id', $b['version'])->value('food_id'),
                    'quantity' => '0.50', 'uom_id' => $serving, 'recipe_version_id' => $b['version'], 'recipe_batch_format_id' => $b['formats'][$code], 'recipe_portion_divisor' => $divisor]);
            }
        }
        DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        DB::table('client_meal_plan_assignments')->where('id', $f['assignment'])->delete();
        $this->assignment($version);
        $n = (int) now()->isoWeekday();
        $url = $this->url($ids[$n]['week7']);
        $r = $this->unchanged($url)->assertJsonPath('equipment.0.min_value', '2.50')->assertJsonPath('equipment.0.tip', 'week вручну')
            ->assertJsonPath('directions.0.text', '1050.00 gram / 1050.00 g; Синтетична каструля 2.50 week вручну. 20 хв, 180°C.');
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['name' => 'Змінений продукт']);
        DB::table('uoms')->where('id', $b['g'])->update(['label' => 'Нова назва']);
        $this->unchanged($url)->assertExactJson($r->json());
        $this->unchanged($this->url($ids[$n]['single1']))->assertJsonPath('equipment.0.min_value', null)
            ->assertJsonPath('equipment.0.tip', 'single вручну');
    }

    public function test_foreign_ids_ownership_dates_and_reassignment_fail_closed(): void
    {
        $f = $this->fixture();
        $url = $this->url($f['items'][1]['id']);
        $r = $this->unchanged($url);
        $this->unchanged($url.'?client_id=999&recipe_version_id=999&format_id=999&divisor=1')->assertExactJson($r->json());
        foreach (['0', '-1', '2147483648', 'nope'] as $id) {
            $this->unchanged($this->url($id), 404);
        }
        $other = DB::table('clients')->insertGetId(['coach_user_id' => $this->coach]);
        DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['owner_client_id' => $other]);
        $this->unchanged($url, 404)->assertJsonMissingPath('title');
        $outside = DB::table('meal_items as i')->join('meals as m', 'm.id', '=', 'i.meal_id')->join('meal_plan_days as d', 'd.id', '=', 'm.meal_plan_day_id')
            ->where('d.meal_plan_version_id', $f['version'])->where('d.day_number', 1)->orderBy('i.id')->value('i.id');
        DB::table('client_meal_plan_assignments')->where('id', $f['assignment'])->update(['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]);
        if (now()->isoWeekday() !== 1) {
            $this->unchanged($this->url($outside), 404);
        }
        DB::table('client_meal_plan_assignments')->where('id', $f['assignment'])->delete();
        $replacement = $this->menuVersion(7);
        $this->assignment($replacement['version']);
        $this->unchanged($this->url($f['items'][2]['id']), 404);
    }

    public static function denied(): array
    {
        return array_map(fn ($reason) => [$reason], ['revoked', 'idle', 'absolute', 'mfa', 'restore', 'disabled', 'anonymous']);
    }

    #[DataProvider('denied')]
    public function test_session_gates_and_read_only_failures(string $reason): void
    {
        $f = $this->fixture();
        $status = 401;
        if ($reason === 'revoked') {
            DB::table('auth_session_states')->where('session_id', $this->sessionId)->update(['revoked_at' => DB::raw('clock_timestamp()')]);
        }
        if ($reason === 'idle') {
            DB::table('sessions')->where('id', $this->sessionId)->update(['last_activity' => time() - 43201]);
        }
        if ($reason === 'absolute') {
            $state = (array) DB::table('auth_session_states')->where('session_id', $this->sessionId)->first();
            DB::table('auth_session_states')->where('session_id', $this->sessionId)->delete();
            foreach (['primary_verified_at', 'absolute_expires_at'] as $key) {
                $state[$key] = CarbonImmutable::parse($state[$key])->subDays(8)->format('Y-m-d H:i:s.uP');
            }
            DB::table('auth_session_states')->insert($state);
        }
        if ($reason === 'mfa') {
            DB::table('user_totp_factors')->insert(['user_id' => $this->user, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp('JBSWY3DPEHPK3PXP'), 'key_version' => 'test_v1', 'confirmed_at' => now(), 'created_at' => now()->subMinute()]);
        }
        if ($reason === 'restore') {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
            $status = 503;
        }
        if ($reason === 'disabled') {
            config(['auth4.enabled' => false]);
            $status = 404;
        }
        if ($reason === 'anonymous') {
            $this->defaultCookies = [];
        }
        Auth::forgetGuards();
        $this->unchanged($this->url($f['items'][1]['id']), $status);
    }

    public static function corruptions(): array
    {
        return array_map(fn ($case) => [$case], ['empty', 'missing_frozen', 'bad_snapshot', 'token', 'unknown_token']);
    }

    #[DataProvider('corruptions')]
    public function test_damaged_historical_data_is_unavailable_without_partial_content(string $case): void
    {
        $f = $this->fixture();
        $version = $f['batch']['version'];
        // Лише synthetic fault injection під table-owner у disposable PG; guards повертаються в finally.
        $table = match ($case) {
            'empty' => 'recipe_ingredients',
            'missing_frozen', 'bad_snapshot' => 'recipe_batch_quantities',
            'token', 'unknown_token' => 'recipe_directions',
        };
        DB::statement('ALTER TABLE '.$table.' DISABLE TRIGGER USER');
        try {
            if ($case === 'empty') {
                // FK зберігаємо: спершу видаляємо frozen children із вимкненим лише business guard.
                DB::statement('ALTER TABLE recipe_batch_quantities DISABLE TRIGGER USER');
                DB::table('recipe_batch_quantities')->where('recipe_version_id', $version)->delete();
                DB::statement('ALTER TABLE recipe_batch_quantities ENABLE TRIGGER USER');
                DB::table($table)->where('recipe_version_id', $version)->delete();
            } elseif ($case === 'missing_frozen') {
                DB::table($table)->where('recipe_version_id', $version)->where('format_code', 'week')->delete();
            } elseif ($case === 'bad_snapshot') {
                DB::table($table)->where('recipe_version_id', $version)->where('format_code', 'week')->update(['input_snapshot' => '{"schema_version":1}']);
            } else {
                DB::table($table)->insert(['recipe_version_id' => $version, 'direction_number' => 1,
                    'direction_text' => $case === 'token' ? '{{ingredient:999999:quantity}}' : '{{timer:unknown}}']);
            }
        } finally {
            DB::statement('ALTER TABLE '.$table.' ENABLE TRIGGER USER');
        }
        $this->unchanged($this->url($f['items'][3]['id']), 404)->assertJsonMissingPath('ingredients')->assertJsonMissingPath('title');
    }

    public function test_safe_photos_plain_text_and_published_history(): void
    {
        $f = $this->fixture();
        config(['presentation.image_hosts' => ['images.example.com']]);
        $url = $this->url($f['items'][1]['id']);
        DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => 'https://images.example.com/cover.jpg']);
        $r = $this->unchanged($url)->assertJsonPath('image_url', 'https://images.example.com/cover.jpg')->assertJsonPath('directions.0.image_url', null);
        foreach (['javascript:alert(1)', 'https://evil.example/image.jpg', 'https://images.example.com/file.svg', 'https://user@images.example.com/image.jpg'] as $image) {
            DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => $image]);
            $this->unchanged($url)->assertJsonPath('image_url', null);
        }
        $new = DB::table('recipe_versions')->insertGetId(['recipe_id' => $f['legacy']['recipe'], 'title' => 'Нова published версія']);
        DB::table('recipe_ingredients')->insert(['recipe_version_id' => $new, 'ingredient_id' => $f['legacy']['ingredient'],
            'uom_id' => $f['legacy']['g'], 'quantity' => '999.00', 'quantity_grams' => '999.00']);
        $food = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
        DB::table('recipe_versions')->where('id', $new)->update(['status' => 'published', 'version_number' => 2, 'published_at' => now(),
            'food_id' => $food, 'kcal_per_serving' => '1.00', 'protein_per_serving' => '1.00', 'fat_per_serving' => '1.00', 'carbs_per_serving' => '1.00']);
        DB::table('recipe_versions')->insert(['recipe_id' => $f['legacy']['recipe'], 'title' => 'Нова чернетка']);
        $this->unchanged($url.'?recipe_version_id='.$new)->assertJsonPath('recipe_version_id', $r->json('recipe_version_id'));
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => DB::table('recipe_directions')->where('recipe_version_id', $f['legacy']['version'])->update(['direction_text' => 'Підміна']));
            $this->fail('Published directions мають бути immutable.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_manual_frozen_can_is_a_whole_physical_batch_without_purchase_rounding(): void
    {
        $batch = null;
        $f = $this->menuVersion(14, prepareBatch: function (array $b) use (&$batch): void {
            $batch = $b;
            $can = DB::table('uoms')->insertGetId(['code' => 'recipe-can', 'kind' => 'count', 'label' => 'банка']);
            DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $can, 'grams_per_uom' => '420.00']);
            $this->storeEvaluated($b, InheritedUomCases::row('recipe-can', '1.00', '420.00', '0.50'));
            DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_ingredient_id=? AND mode='manual'", [$b['row']]);
        });
        $this->assignment($f['version']);
        $items = app(ClientMealPlanDays::class)->forClient($this->client, 1)['days'][0]['meals'][0]['items'];
        $url = $this->url($items[3]['id']);
        $r = $this->unchanged($url)->assertJsonPath('ingredients.0.quantity', '1.00')->assertJsonPath('ingredients.0.quantity_grams', '420.00')
            ->assertJsonPath('ingredients.0.unit_label', 'банка');
        $this->unchanged($this->url($items[4]['id']))->assertJsonPath('ingredients.0.quantity', '0.50');
        DB::table('ingredient_uoms')->where('ingredient_id', $batch['ingredient'])->update(['grams_per_uom' => '999.00']);
        DB::table('uoms')->where('code', 'recipe-can')->update(['label' => 'Змінена одиниця']);
        $this->unchanged($url)->assertExactJson($r->json());
    }

    public function test_direction_photos_are_validated_and_text_is_never_html_markup(): void
    {
        config(['presentation.image_hosts' => ['images.example.com']]);
        $f = $this->menuVersion(14, prepareBatch: function (array $b): void {
            foreach (['https://images.example.com/step.jpg', 'https://evil.example/step.jpg', 'javascript:alert(1)'] as $i => $image) {
                DB::table('recipe_directions')->insert(['recipe_version_id' => $b['version'], 'direction_number' => $i + 1,
                    'direction_text' => '<b>Авторський текст</b> {{ingredient:'.$b['row'].':quantity}}', 'image_url' => $image]);
            }
        });
        $this->assignment($f['version']);
        $items = app(ClientMealPlanDays::class)->forClient($this->client, 1)['days'][0]['meals'][0]['items'];
        $this->unchanged($this->url($items[2]['id']))->assertJsonPath('directions.0.image_url', 'https://images.example.com/step.jpg')
            ->assertJsonPath('directions.1.image_url', null)->assertJsonPath('directions.2.image_url', null)
            ->assertJsonPath('directions.0.text', '<b>Авторський текст</b> 150.00 gram');
    }
}
