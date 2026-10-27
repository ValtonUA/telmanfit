<?php

namespace Tests\Postgres;

use App\Models\User;
use App\Services\Cooking\CookingTemplates;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

class CookingTemplatesTest extends PostgresTestCase
{
    use BatchFixture, PlanFixture;

    private User $actor;

    private array $recipes = [];

    private int $type;

    private int $serving;

    private int $ingredientFood;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic cooking admin', 'role' => 'admin']));
        DB::table('uoms')->insert(['code' => 'g', 'label' => 'gram', 'kind' => 'mass']);
        $legacy = $this->populatedPlan($this->actor->id);
        $this->ingredientFood = $legacy['food'];
        $this->type = $legacy['type'];
        $this->serving = DB::table('uoms')->where('code', 'serving')->value('id');
        $rv = DB::table('recipe_versions')->where('editor_contract', 'legacy_v1')->sole();
        $this->recipes[] = ['version' => $rv->id, 'food' => $rv->food_id, 'format' => null, 'divisor' => '1.00',
            'row' => DB::table('recipe_ingredients')->where('recipe_version_id', $rv->id)->value('id')];
        foreach ([['single', 1], ['week', 7], ['half_week', 3], ['half_week', 4], ['single', 1]] as [$code, $divisor]) {
            $b = $this->batch();
            $this->publish($b['version']);
            $this->recipes[] = ['version' => $b['version'], 'food' => DB::table('recipe_versions')->where('id', $b['version'])->value('food_id'),
                'format' => $b['formats'][$code], 'divisor' => (string) $divisor, 'row' => $b['row']];
        }
        $history = $this->history();
        (require database_path('migrations/2026_10_28_000000_create_cooking_templates.php'))->up();
        $this->assertSame($history, $this->history());
    }

    private function history(): array
    {
        $result = [];
        foreach (['recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items'] as $table) {
            $result[$table] = DB::table($table)->orderByRaw('to_jsonb('.$table.')::text')->get()->toJson();
        }

        return $result;
    }

    private function menu(string $quantity = '0.50', int $weeks = 1, ?int $owner = null, bool $recipesEveryDay = false): int
    {
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Synthetic six recipe menu', 'owner_client_id' => $owner]);
        $v = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $g = DB::table('uoms')->where('code', 'g')->value('id');
        for ($day = 1; $day <= 7 * $weeks; $day++) {
            $d = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $v, 'day_number' => $day]);
            $m = DB::table('meals')->insertGetId(['meal_plan_day_id' => $d, 'meal_type_id' => $this->type]);
            DB::table('meal_items')->insert(['meal_id' => $m, 'food_id' => $this->ingredientFood, 'quantity' => '100', 'uom_id' => $g, 'resolved_quantity_grams' => '100']);
            if ($recipesEveryDay || ($day - 1) % 7 === 0) {
                foreach ($this->recipes as $n => $r) {
                    DB::table('meal_items')->insert(['meal_id' => $m, 'food_id' => $r['food'], 'quantity' => $quantity, 'uom_id' => $this->serving, 'sort_order' => $n + 1,
                        'recipe_version_id' => $r['format'] === null ? null : $r['version'], 'recipe_batch_format_id' => $r['format'],
                        'recipe_portion_divisor' => $r['format'] === null ? null : $r['divisor']]);
                }
            }
        }
        DB::table('meal_plan_versions')->where('id', $v)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);

        return $v;
    }

    private function content(): array
    {
        return ['schema_version' => 1, 'stages' => [['id' => 'prepare', 'title' => 'Приготування', 'blocks' => [
            ['id' => 'action', 'type' => 'action', 'parts' => ['Синтетичний авторський крок: 700 мл води вручну.']],
            ['id' => 'timer', 'type' => 'timer', 'label' => 'Перевірка', 'seconds' => 1200, 'message' => 'Перевірте готовність.'],
        ]], ['id' => 'pack', 'title' => 'Фасування та зберігання', 'blocks' => [
            ['id' => 'pack-action', 'type' => 'action', 'parts' => ['Синтетична інструкція автора; не термін придатності.']],
        ]]]];
    }

    private function draft(?int $source = null): array
    {
        $source ??= $this->menu();
        $template = DB::table('cooking_templates')->insertGetId(['title' => 'Synthetic pilot', 'created_by' => $this->actor->id]);
        $v = DB::table('cooking_template_versions')->insertGetId(['template_id' => $template, 'version_number' => 1, 'source_plan_version_id' => $source, 'source_week' => 1, 'mode' => 1]);
        $session = DB::table('cooking_sessions')->insertGetId(['version_id' => $v, 'position' => 1, 'day' => 1, 'estimated_seconds' => 3600, 'content' => json_encode($this->content())]);
        $outputs = [];
        foreach ($this->recipes as $n => $r) {
            $o = DB::table('cooking_outputs')->insertGetId(['version_id' => $v, 'session_id' => $session, 'recipe_version_id' => $r['version'], 'format_id' => $r['format'], 'portion_divisor' => $r['divisor']]);
            DB::table('cooking_allocations')->insert(['version_id' => $v, 'day' => 1, 'meal_position' => 1, 'item_position' => $n + 2, 'output_id' => $o, 'portions' => '0.50']);
            $outputs[] = $o;
        }

        return compact('template', 'v', 'session', 'outputs', 'source');
    }

    private function revision(int $v): int
    {
        return (int) DB::table('cooking_template_versions')->where('id', $v)->value('revision');
    }

    private function review(int $v): void
    {
        app(CookingTemplates::class)->review($this->actor, $v, $this->revision($v), array_fill_keys(['suitability', 'safety', 'manual_parameters', 'no_process_water_tokens'], true));
    }

    private function publishCooking(int $v): void
    {
        $this->review($v);
        app(CookingTemplates::class)->publish($this->actor, $v, $this->revision($v));
    }

    private function rejected(callable $f, string $class = QueryException::class): void
    {
        DB::beginTransaction();
        try {
            $f();
            $this->fail('Невалідна операція повинна бути відхилена');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        } finally {
            DB::rollBack();
        }
    }

    public function test_six_recipe_pilot_balances_immutable_versions_and_forward_migration(): void
    {
        $this->assertSame(file_get_contents(database_path('sql/cooking/00_templates.sql')), file_get_contents(base_path('docs/17_cooking_templates.sql')));
        $d = $this->draft();
        $this->publishCooking($d['v']);
        $this->assertSame(6, DB::table('cooking_outputs')->count());
        $balances = DB::table('cooking_output_balances')->orderBy('id')->get();
        $this->assertSame('0.50', $balances[0]->allocated);
        $this->assertSame('0.50', $balances[0]->left_over);
        $this->assertSame('6.50', $balances[2]->left_over);
        foreach (['cooking_template_versions' => ['id' => $d['v'], 'mode' => 2], 'cooking_sessions' => ['id' => $d['session'], 'day' => 2],
            'cooking_outputs' => ['id' => $d['outputs'][0], 'portion_divisor' => 2]] as $table => $change) {
            $this->rejected(fn () => DB::table($table)->where('id', $change['id'])->update(array_slice($change, 1)));
            $this->rejected(fn () => DB::table($table)->where('id', $change['id'])->delete());
        }
        $this->rejected(fn () => DB::table('cooking_allocations')->where('version_id', $d['v'])->delete());
        $next = DB::table('cooking_template_versions')->insertGetId(['template_id' => $d['template'], 'version_number' => 2, 'previous_version_id' => $d['v'], 'source_plan_version_id' => $d['source'], 'source_week' => 1, 'mode' => 1]);
        $this->assertNotSame($d['v'], $next);
        $this->rejected(fn () => (require database_path('migrations/2026_10_28_000000_create_cooking_templates.php'))->down(), \RuntimeException::class);
    }

    public function test_reuse_compares_entire_existing_week_excludes_ids_and_never_cycles(): void
    {
        $d = $this->draft();
        $this->publishCooking($d['v']);
        $other = $this->menu(weeks: 2);
        $pattern = fn (int $v, int $w) => DB::selectOne('SELECT cooking_menu_pattern(?,?)::text AS p', [$v, $w])->p;
        $this->assertSame($pattern($d['source'], 1), $pattern($other, 1));
        $this->assertSame($pattern($other, 1), $pattern($other, 2));
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $this->assertSame($pattern($d['source'], 1), $pattern($other, 1));
        $this->assertNotSame($pattern($other, 1), $pattern($this->menu('0.51'), 1));
        $this->rejected(fn () => $pattern($d['source'], 2));
        $this->rejected(fn () => $pattern($other, 0));
    }

    public function test_access_two_clients_partial_week_revocation_owner_and_wrong_mode(): void
    {
        $d = $this->draft();
        $this->publishCooking($d['v']);
        $available = fn ($client, $assignment, $version = null, $week = 1, $mode = 1) => DB::selectOne('SELECT cooking_available(?,?,?,?,?,?::date) AS ok', [$client, $assignment, $version ?? $d['v'], $week, $mode, '2026-10-28'])->ok;
        $clients = [];
        foreach ([1, 2] as $n) {
            $c = DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]);
            $a = DB::table('client_meal_plan_assignments')->insertGetId(['client_id' => $c, 'meal_plan_version_id' => $d['source'], 'created_by' => $this->actor->id, 'start_date' => '2026-10-26', 'end_date' => '2026-11-01']);
            $this->assertTrue($available($c, $a));
            $this->assertFalse($available($c, $a, mode: 2));
            $this->assertFalse($available($c, $a, week: 2));
            $clients[] = [$c, $a];
        }
        $this->assertFalse($available($clients[0][0], $clients[1][1]));
        DB::table('client_meal_plan_assignments')->where('id', $clients[0][1])->update(['start_date' => '2026-10-28']);
        $this->assertFalse($available(...$clients[0]));
        DB::table('client_meal_plan_assignments')->where('id', $clients[1][1])->update(['end_date' => '2026-10-27']);
        $this->assertFalse($available(...$clients[1]));
        $draft = $this->draft();
        $this->assertFalse($available($clients[0][0], $clients[0][1], $draft['v']));
        $private = $this->menu(owner: $clients[0][0]);
        $newClient = DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]);
        // Навмисно невалідне synthetic призначення: SQL read guard має відмовити.
        $foreign = DB::table('client_meal_plan_assignments')->insertGetId(['client_id' => $newClient, 'meal_plan_version_id' => $private,
            'created_by' => $this->actor->id, 'start_date' => '2026-10-26', 'end_date' => '2026-11-01']);
        $this->assertFalse($available($newClient, $foreign));
    }

    public function test_review_is_explicit_revision_bound_and_access_controlled(): void
    {
        $d = $this->draft();
        $service = app(CookingTemplates::class);
        $this->rejected(fn () => $service->publish($this->actor, $d['v'], $this->revision($d['v'])), ValidationException::class);
        $this->rejected(fn () => $service->review($this->actor, $d['v'], $this->revision($d['v']), ['safety' => true]), ValidationException::class);
        $other = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic coach', 'role' => 'coach']));
        $this->rejected(fn () => $service->review($other, $d['v'], $this->revision($d['v']), []), HttpException::class);
        $this->review($d['v']);
        $old = $this->revision($d['v']);
        DB::table('cooking_sessions')->where('id', $d['session'])->update(['equipment' => 'Авторська каструля 2 л']);
        $this->assertNull(DB::table('cooking_template_versions')->where('id', $d['v'])->value('reviewed_revision'));
        $this->rejected(fn () => $service->publish($this->actor, $d['v'], $old), HttpException::class);
        $this->rejected(fn () => DB::table('cooking_template_versions')->where('id', $d['v'])->update(['status' => 'published', 'published_at' => now()]));
        $this->publishCooking($d['v']);
    }

    public function test_negative_coverage_divisor_version_day_portions_extra_slot_and_mode(): void
    {
        $d = $this->draft();
        $assert = fn () => DB::select('SELECT cooking_assert_version(?)', [$d['v']]);
        $assert();
        foreach ([['portion_divisor' => 4], ['recipe_version_id' => $this->recipes[5]['version'], 'format_id' => $this->recipes[5]['format']]] as $change) {
            $this->rejected(function () use ($d, $change, $assert) {
                DB::table('cooking_outputs')->where('id', $d['outputs'][3])->update($change);
                $assert();
            });
        }
        foreach ([['portions' => '1.01'], ['day' => 2], ['item_position' => 99]] as $change) {
            $this->rejected(function () use ($d, $change, $assert) {
                DB::table('cooking_allocations')->where('output_id', $d['outputs'][0])->update($change);
                $assert();
            });
        }
        $this->rejected(function () use ($d, $assert) {
            DB::table('cooking_allocations')->where('output_id', $d['outputs'][0])->delete();
            $assert();
        });
        $this->rejected(function () use ($d, $assert) {
            DB::table('cooking_sessions')->where('id', $d['session'])->update(['day' => 2]);
            $assert();
        });
        foreach ([0, 3, 8] as $mode) {
            $this->rejected(fn () => DB::table('cooking_template_versions')->where('id', $d['v'])->update(['mode' => $mode]));
        }
        foreach ([2, 7] as $mode) {
            $this->rejected(function () use ($d, $mode, $assert) {
                DB::table('cooking_template_versions')->where('id', $d['v'])->update(['mode' => $mode]);
                $assert();
            });
        }
    }

    public function test_tokens_use_one_physical_batch_and_only_explicit_sources(): void
    {
        $d = $this->draft();
        $token = ['metric' => 'grams', 'sources' => [['output' => $d['outputs'][2], 'row' => $this->recipes[2]['row']]]];
        $resolve = fn ($t) => json_decode(DB::selectOne('SELECT cooking_token(?,?::jsonb)::text AS t', [$d['session'], json_encode($t)])->t, true);
        $value = $resolve($token);
        $this->assertSame('1050.00', $value['value']);
        $token['metric'] = 'quantity';
        $this->assertSame('1050.00', $resolve($token)['value']);
        $otherOutput = DB::table('cooking_outputs')->insertGetId(['version_id' => $d['v'], 'session_id' => $d['session'], 'recipe_version_id' => $this->recipes[2]['version'], 'format_id' => $this->recipes[2]['format'], 'portion_divisor' => 7]);
        $sum = $token;
        $sum['sources'][] = ['output' => $otherOutput, 'row' => $this->recipes[2]['row']];
        $this->assertSame('2100.00', $resolve($sum)['value']);
        $this->assertSame('1050.00', $resolve($token)['value']);
        foreach ([['metric' => 'water'] + $token, ['metric' => 'equipment'] + $token, ['metric' => 'formula'] + $token,
            ['metric' => 'grams', 'sources' => [$token['sources'][0], $token['sources'][0]]],
            ['metric' => 'grams', 'sources' => [['output' => $d['outputs'][0], 'row' => $this->recipes[2]['row']]]],
            ['metric' => 'grams', 'sources' => [$token['sources'][0], ['output' => $d['outputs'][1], 'row' => $this->recipes[1]['row']]]],
            ['metric' => 'grams', 'sources' => [['output' => 2147483648, 'row' => 1]]]] as $bad) {
            $this->rejected(fn () => $resolve($bad));
        }
    }

    public function test_json_stable_ids_unknown_blocks_types_html_and_images(): void
    {
        $d = $this->draft();
        foreach (['bad-type', 'duplicate', 'html', 'timer', 'missing', 'schema'] as $case) {
            $c = $this->content();
            match ($case) {
                'bad-type' => $c['stages'][0]['blocks'][0]['type'] = 'storage',
                'duplicate' => $c['stages'][0]['blocks'][0]['id'] = 'prepare',
                'html' => $c['stages'][0]['blocks'][0]['parts'] = ['<script>x</script>'],
                'timer' => $c['stages'][0]['blocks'][1]['seconds'] = 1.5,
                'missing' => $c['stages'][0]['blocks'][0]['parts'] = null,
                'schema' => $c['schema_version'] = 2,
            };
            $this->rejected(fn () => DB::table('cooking_sessions')->where('id', $d['session'])->update(['content' => json_encode($c)]));
        }
        $c = $this->content();
        $c['stages'] = array_reverse($c['stages']);
        DB::table('cooking_sessions')->where('id', $d['session'])->update(['content' => json_encode($c)]);
        $this->assertSame('pack', json_decode(DB::table('cooking_sessions')->where('id', $d['session'])->value('content'), true)['stages'][0]['id']);
        $c['stages'][0]['blocks'][] = ['id' => 'photo', 'type' => 'image', 'url' => 'https://untrusted.example.com/image.png', 'alt' => 'Фото'];
        DB::table('cooking_sessions')->where('id', $d['session'])->update(['content' => json_encode($c)]);
        $this->rejected(fn () => $this->review($d['v']), ValidationException::class);
        config(['presentation.image_hosts' => ['untrusted.example.com']]);
        $this->review($d['v']);
    }

    public function test_dangling_token_blocks_review_and_database_publication(): void
    {
        $d = $this->draft();
        $content = $this->content();
        $content['stages'][0]['blocks'][0]['parts'] = [['metric' => 'grams', 'sources' => [['output' => $d['outputs'][0], 'row' => $this->recipes[1]['row']]]]];
        DB::table('cooking_sessions')->where('id', $d['session'])->update(['content' => json_encode($content)]);
        $this->rejected(fn () => $this->review($d['v']));
        $this->rejected(function () use ($d) {
            DB::table('cooking_template_versions')->where('id', $d['v'])->update(['reviewed_revision' => $this->revision($d['v']), 'reviewed_by' => $this->actor->id, 'reviewed_at' => now()]);
            DB::table('cooking_template_versions')->where('id', $d['v'])->update(['status' => 'published', 'published_at' => now()]);
        });
    }

    public function test_frozen_quantity_context_and_cross_session_sources_are_rejected(): void
    {
        $d = $this->draft();
        $r = $this->recipes[2];
        $foreignSession = DB::table('cooking_sessions')->insertGetId(['version_id' => $d['v'], 'position' => 2, 'day' => 2, 'estimated_seconds' => 1, 'content' => json_encode($this->content())]);
        $foreignOutput = DB::table('cooking_outputs')->insertGetId(['version_id' => $d['v'], 'session_id' => $foreignSession, 'recipe_version_id' => $r['version'], 'format_id' => $r['format'], 'portion_divisor' => 7]);
        $token = ['metric' => 'grams', 'sources' => [['output' => $foreignOutput, 'row' => $r['row']]]];
        $this->rejected(fn () => DB::select('SELECT cooking_token(?,?::jsonb)', [$d['session'], json_encode($token)]));
        $a = DB::table('recipe_batch_formats')->where('recipe_version_id', $r['version'])->where('code', 'single')->value('id');
        $aOutput = DB::table('cooking_outputs')->insertGetId(['version_id' => $d['v'], 'session_id' => $d['session'], 'recipe_version_id' => $r['version'], 'format_id' => $a, 'portion_divisor' => 1]);
        $token = ['metric' => 'quantity', 'sources' => [['output' => $d['outputs'][2], 'row' => $r['row']], ['output' => $aOutput, 'row' => $r['row']]]];
        $this->rejected(fn () => DB::select('SELECT cooking_token(?,?::jsonb)', [$d['session'], json_encode($token)]));
        $token['metric'] = 'grams';
        $this->assertSame('1200.00', json_decode(DB::selectOne('SELECT cooking_token(?,?::jsonb)::text AS t', [$d['session'], json_encode($token)])->t, true)['value']);
    }

    public function test_only_prepared_two_and_seven_session_modes_can_publish(): void
    {
        foreach ([2, 7] as $mode) {
            $source = $this->menu(recipesEveryDay: true);
            $template = DB::table('cooking_templates')->insertGetId(['title' => 'Synthetic prepared mode', 'created_by' => $this->actor->id]);
            $v = DB::table('cooking_template_versions')->insertGetId(['template_id' => $template, 'version_number' => 1, 'source_plan_version_id' => $source, 'source_week' => 1, 'mode' => $mode]);
            $sessions = [];
            foreach ($mode === 2 ? [1, 4] : range(1, 7) as $position => $day) {
                $sessions[$day] = DB::table('cooking_sessions')->insertGetId(['version_id' => $v, 'position' => $position + 1, 'day' => $day, 'estimated_seconds' => 3600, 'content' => json_encode($this->content())]);
            }
            foreach (range(1, 7) as $day) {
                foreach ($this->recipes as $n => $r) {
                    $sessionDay = $mode === 7 ? $day : ($day < 4 ? 1 : 4);
                    $o = DB::table('cooking_outputs')->insertGetId(['version_id' => $v, 'session_id' => $sessions[$sessionDay], 'recipe_version_id' => $r['version'], 'format_id' => $r['format'], 'portion_divisor' => $r['divisor']]);
                    DB::table('cooking_allocations')->insert(['version_id' => $v, 'day' => $day, 'meal_position' => 1, 'item_position' => $n + 2, 'output_id' => $o, 'portions' => '0.50']);
                }
            }
            $this->publishCooking($v);
            $this->assertSame('published', DB::table('cooking_template_versions')->where('id', $v)->value('status'));
        }
    }
}
