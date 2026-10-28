<?php

namespace Tests\Auth4;

use App\Mail\EmailOtp;
use App\Models\User;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use App\Services\Cooking\CookingEditor;
use App\Services\Cooking\CookingTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Recipes\BatchFixture;
use Tests\TestCase;

/** Real OTP/ClientSession HTTP boundary and exhaustive no-write gate. */
class ClientCookingTest extends TestCase
{
    use BatchFixture;

    protected string $sessionId;

    private int $coachId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        $coach = DB::table('users')->insertGetId(['name' => 'Synthetic cooking coach', 'role' => 'admin']);
        $this->coachId = $coach;
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic cooking client', 'email' => 'cooking-api@example.invalid',
            'email_normalized' => 'cooking-api@example.invalid', 'email_verified_at' => now()]);
        $this->clientId = DB::table('clients')->insertGetId(['user_id' => $user, 'coach_user_id' => $coach]);
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

    public static function physicalBatches(): array
    {
        return [
            'A' => [[['single', '1.00']], '100'],
            'B' => [[['week', '7.00']], '700'],
            'C3' => [[['half_week', '3.00']], '350'],
            'C4' => [[['half_week', '4.00']], '350'],
            'B+C' => [[['week', '7.00'], ['half_week', '3.00']], '1050'],
            'C3+C4' => [[['half_week', '3.00'], ['half_week', '4.00']], '700'],
            'two B+C' => [[['week', '7.00'], ['week', '7.00'], ['half_week', '4.00']], '1750'],
        ];
    }

    private function publishedSession(array $selectors): array
    {
        $batch = $this->batch();
        DB::table('recipe_ingredients')->where('id', $batch['row'])->update(['quantity' => '100.00', 'quantity_grams' => '100.00']);
        foreach (['week' => '700.00', 'half_week' => '350.00'] as $code => $grams) {
            DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $batch['row'])->where('format_code', $code)
                ->update(['quantity' => $grams, 'quantity_grams' => $grams]);
        }
        $this->syncTotals($batch['version']);
        $this->publish($batch['version']);
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетична кухня A/B/C', 'week_start_weekday' => now()->isoWeekday()]);
        $planVersion = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $type = DB::table('meal_types')->insertGetId(['code' => 'cooking-api-'.bin2hex(random_bytes(4)), 'title' => 'Обід']);
        $serving = DB::table('uoms')->where('code', 'serving')->value('id')
            ?? DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
        $food = DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id');
        for ($day = 1; $day <= 7; $day++) {
            $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $planVersion, 'day_number' => $day]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $dayId, 'meal_type_id' => $type]);
            foreach ($selectors as $index => [$code, $divisor]) {
                DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $food, 'quantity' => '0.10', 'uom_id' => $serving,
                    'sort_order' => $index, 'recipe_version_id' => $batch['version'],
                    'recipe_batch_format_id' => $batch['formats'][$code], 'recipe_portion_divisor' => (int) $divisor]);
            }
        }
        DB::table('meal_plan_versions')->where('id', $planVersion)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        $actor = User::findOrFail($this->coachId);
        $editor = app(CookingEditor::class);
        $version = $editor->create($actor, ['title' => 'Синтетичні фізичні партії', 'source_plan_version_id' => $planVersion, 'source_week' => 1, 'mode' => 1]);
        $revision = fn () => (int) DB::table('cooking_template_versions')->where('id', $version)->value('revision');
        $session = $editor->session($actor, $version, $revision(), ['day' => 1, 'position' => 1, 'estimated_seconds' => 60,
            'content' => ['schema_version' => 1, 'stages' => [['id' => 'prepare', 'title' => 'Приготуйте',
                'blocks' => [['id' => 'action', 'type' => 'action', 'parts' => ['Авторська інструкція.']]]]]]]);
        $outputs = [];
        foreach ($selectors as [$code, $divisor]) {
            $selector = collect($editor->selectors($version))->filter(fn ($slot) => (int) $slot['format_id'] === $batch['formats'][$code]
                && bccomp((string) $slot['divisor'], $divisor, 2) === 0)->keys()->sole();
            $outputs[] = $editor->output($actor, $version, $revision(), $session, $selector);
        }
        foreach (json_decode(DB::table('cooking_template_versions')->where('id', $version)->value('menu_pattern'), true)['items'] as $slot) {
            $editor->allocate($actor, $version, $revision(), $slot['day'], $slot['meal_position'], $slot['item_position'], $outputs[$slot['item_position'] - 1]);
        }
        $cooking = app(CookingTemplates::class);
        $cooking->review($actor, $version, $revision(), array_fill_keys(['suitability', 'safety', 'manual_parameters', 'no_process_water_tokens'], true));
        $cooking->publish($actor, $version, $revision());
        $cooking->select($actor, $version, 0);
        DB::table('client_meal_plan_assignments')->insert(['client_id' => $this->clientId, 'meal_plan_version_id' => $planVersion,
            'created_by' => $this->coachId, 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(6)->toDateString()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return compact('version', 'session', 'batch', 'outputs');
    }

    #[DataProvider('physicalBatches')]
    public function test_api_uses_exact_physical_format_without_allocation_scaling(array $selectors, string $grams): void
    {
        $fixture = $this->publishedSession($selectors);
        // Чинний каталог не є джерелом назви чи кількостей опублікованої партії.
        DB::table('ingredients')->where('id', $fixture['batch']['ingredient'])->update(['name' => 'Змінено в каталозі']);
        $before = $this->snapshot();
        $url = '/api/meal-plan/cooking/'.$fixture['version'].'/sessions/'.$fixture['session'].'?week=1&mode=1';
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('session.ingredients', [['id' => (string) $fixture['batch']['ingredient'], 'name' => 'Synthetic rice',
                'quantity_grams' => $grams, 'image_url' => null]])
            ->assertJsonCount(count($selectors), 'session.dishes');
        $this->head($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_api_does_not_expose_another_clients_published_session(): void
    {
        $fixture = $this->publishedSession([['week', '7.00']]);
        $other = DB::table('clients')->insertGetId(['coach_user_id' => $this->coachId]);
        DB::table('client_meal_plan_assignments')->where('client_id', $this->clientId)->update(['client_id' => $other]);
        $before = $this->snapshot();
        $url = '/api/meal-plan/cooking/'.$fixture['version'].'/sessions/'.$fixture['session'].'?week=1&mode=1&client_id='.$other;
        $this->getJson($url)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->head($url)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->snapshot());
    }

    public static function brokenFrozenQuantities(): array
    {
        return [['missing'], ['duplicate'], ['conflicting']];
    }

    #[DataProvider('brokenFrozenQuantities')]
    public function test_api_rejects_incomplete_or_conflicting_frozen_projection(string $fault): void
    {
        $fixture = $this->publishedSession([['week', '7.00']]);
        $recipe = $fixture['batch']['version'];
        $snapshot = json_decode(DB::selectOne('SELECT cooking_recipe_snapshot(?) AS data', [$recipe])->data, true);
        foreach ($snapshot['quantities'] as $index => $quantity) {
            if ($quantity['format_id'] !== $fixture['batch']['formats']['week']) {
                continue;
            }
            if ($fault === 'missing') {
                unset($snapshot['quantities'][$index]);
            } elseif ($fault === 'duplicate') {
                $snapshot['quantities'][] = $quantity;
            } else {
                $snapshot['quantities'][$index]['format_code'] = 'half_week';
            }
            break;
        }
        $snapshot['quantities'] = array_values($snapshot['quantities']);
        // Лише transaction-scoped synthetic fault: published rows і DB guards не змінюємо.
        $body = 'SELECT '.DB::getPdo()->quote(json_encode($snapshot, JSON_THROW_ON_ERROR)).'::jsonb WHERE p='.(int) $recipe;
        DB::unprepared('CREATE OR REPLACE FUNCTION cooking_recipe_snapshot(p integer) RETURNS jsonb LANGUAGE sql STABLE AS '.DB::getPdo()->quote($body));
        $before = $this->snapshot();
        $url = '/api/meal-plan/cooking/'.$fixture['version'].'/sessions/'.$fixture['session'].'?week=1&mode=1';
        $this->getJson($url)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('session.ingredients');
        $this->assertSame($before, $this->snapshot());
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
        $response = $this->head('/api/meal-plan/cooking?week=1');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->snapshot(), 'HEAD must not mutate sessions or any table/xmin.');
    }

    public function test_missing_client_session_is_denied_without_caching(): void
    {
        unset($this->defaultCookies['telmanfit_client_session']);
        Auth::forgetGuards();
        $before = $this->snapshot();

        $this->getJson('/api/meal-plan/cooking?week=1')
            ->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        Auth::forgetGuards();
        $this->head('/api/meal-plan/cooking?week=1')
            ->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame($before, $this->snapshot(), 'Запити без cookie не повинні змінювати БД або метадані сесій.');
    }
}
