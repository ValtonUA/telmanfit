<?php

namespace Tests\Postgres;

use App\Filament\Pages\RecipeBatches;
use App\Models\User;
use App\Services\Recipes\BatchEditor;
use App\Services\Recipes\RecipeTokens;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Recipes\BatchFixture;

class BatchEditorTest extends PostgresTestCase
{
    use BatchFixture;

    private BatchEditor $editor;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_24_010000_create_recipe_batches.php'))->up();
        (require database_path('migrations/2026_10_24_020000_complete_recipe_equipment.php'))->up();
        config(['recipes.batch_editor_enabled' => true]);
        $this->editor = app(BatchEditor::class);
        $actorId = DB::table('users')->insertGetId(['name' => 'Synthetic author', 'role' => 'admin']);
        $this->actor = User::findOrFail($actorId);
    }

    private function state(array $b): array
    {
        return $this->editor->open($this->actor, $b['version']);
    }

    private function commit(int $id, array $state, bool $refresh = false, bool $ack = false): array
    {
        $preview = $this->editor->preview($this->actor, $id, $state['revision'], $state['payload']);
        $this->editor->save($this->actor, $id, $state['revision'], $state['payload'], $preview['token'], $refresh, $ack);

        return $this->editor->open($this->actor, $id);
    }

    private function denied(callable $action, string $contains): void
    {
        try {
            $action();
            $this->fail('Очікувалася відмова');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    private function publishedForClone(): array
    {
        $b = $this->batch();
        $state = $this->equipmentState($b);
        $category = DB::table('recipe_categories')->insertGetId(['name' => 'Clone recipe category']);
        $ingredientCategory = DB::table('ingredient_categories')->insertGetId(['name' => 'Clone ingredient category']);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['ingredient_category_id' => $ingredientCategory]);
        $unit = DB::table('uoms')->insertGetId(['code' => 'clone-can', 'label' => 'банка', 'kind' => 'count']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $unit, 'grams_per_uom' => '400.00']);
        $state['payload']['category_id'] = (string) $category;
        $state['payload']['components'] = [['id' => 'new-base', 'title' => 'Основа']];
        $state['payload']['rows'][0]['component_id'] = 'new-base';
        $state['payload']['rows'][0]['week'] = ['mode' => 'manual', 'quantity' => '3.00', 'uom_id' => (string) $unit, 'rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true];
        $state['payload']['rows'][0]['auto_policies'] = ['clone-can' => ['rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true]];
        $state['payload']['equipment'][0]['week'] = ['mode' => 'manual', 'min_value' => '2.00', 'uom_id' => $state['payload']['equipment'][0]['uom_id'], 'tip' => 'Manual tip'];
        $side = $state['payload']['rows'][0];
        $side['id'] = 'pending:clone-side';
        $side['quantity'] = '80.00';
        $side['week'] = ['mode' => 'auto', 'quantity' => '', 'uom_id' => (string) $b['g'], 'rounding' => 'exact', 'step' => '0.01'];
        $side['half_week'] = ['mode' => 'manual', 'quantity' => '280.00', 'uom_id' => (string) $b['g'], 'rounding' => 'exact', 'step' => '0.01'];
        $state['payload']['rows'][] = $side;
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Nullable clone tool']);
        $state['payload']['equipment'][] = ['id' => 'new-nullable-tool', 'kitchen_equipment_id' => (string) $tool, 'min_value' => '', 'uom_id' => '', 'scaling_mode' => 'fixed', 'tip' => 'Без числової вимоги',
            'half_week' => ['mode' => 'manual', 'min_value' => '', 'uom_id' => '', 'tip' => 'Ручна C']];
        $state['payload']['directions'] = ['Взяти {{ingredient:'.$b['row'].':quantity}}, {{equipment:new-tool:quantity}}; 20 хв 180°C'];
        $state = $this->commit($b['version'], $state, refresh: true, ack: true);
        // Metadata поза поточним UI теж належить агрегату.
        DB::table('recipe_directions')->where('recipe_version_id', $b['version'])->update(['image_url' => 'https://example.invalid/step.png']);
        $state = $this->state($b);
        $preview = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->editor->publish($this->actor, $b['version'], $state['revision'], $preview['token']);

        return $b;
    }

    public function test_clone_preserves_frozen_aggregate_remaps_every_child_and_requires_new_review(): void
    {
        $b = $this->publishedForClone();
        $source = $this->state($b);
        $tables = ['recipe_versions', 'recipe_components', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities', 'recipe_kitchen_equipment', 'recipe_batch_equipment', 'recipe_directions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->where($table === 'recipe_versions' ? 'id' : 'recipe_version_id', $b['version'])->get()->all();
        }
        $recipe = DB::table('recipes')->find($before['recipe_versions'][0]->recipe_id);
        $foods = DB::table('foods')->get()->all();
        $serving = DB::table('uoms')->insertGetId(['code' => 'serving', 'label' => 'порція', 'kind' => 'serving']);
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Historical assigned plan']);
        $planVersion = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $type = DB::table('meal_types')->insertGetId(['code' => 'clone-meal', 'title' => 'Meal']);
        for ($day = 1; $day <= 7; $day++) {
            $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $planVersion, 'day_number' => $day]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $dayId, 'meal_type_id' => $type]);
            DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $before['recipe_versions'][0]->food_id, 'quantity' => '1.00', 'uom_id' => $serving]);
        }
        DB::table('meal_plan_versions')->where('id', $planVersion)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        $client = DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]);
        DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $planVersion, 'start_date' => '2026-01-01']);
        $historical = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments'] as $table) {
            $historical[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        $id = $this->editor->createVersion($this->actor, $b['version'], $source['revision']);
        $v = DB::table('recipe_versions')->find($id);
        $this->assertSame($recipe->id, $v->recipe_id);
        $this->assertSame($b['version'], $v->source_recipe_version_id);
        $this->assertSame('draft', $v->status);
        foreach (['food_id', 'version_number', 'published_at', 'publication_review'] as $field) {
            $this->assertNull($v->$field);
        }
        $copy = $this->editor->open($this->actor, $id);
        foreach (['components', 'rows', 'equipment'] as $field) {
            $this->assertNotSame($source['payload'][$field][0]['id'], $copy['payload'][$field][0]['id']);
        }
        $this->assertSame($copy['payload']['components'][0]['id'], $copy['payload']['rows'][0]['component_id']);
        $this->assertSame(RecipeTokens::remap($source['payload']['directions'][0], [$b['row'] => $copy['payload']['rows'][0]['id']], [$source['payload']['equipment'][0]['id'] => $copy['payload']['equipment'][0]['id']]), $copy['payload']['directions'][0]);
        $this->assertSame('https://example.invalid/step.png', DB::table('recipe_directions')->where('recipe_version_id', $id)->value('image_url'));
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->where($table === 'recipe_versions' ? 'id' : 'recipe_version_id', $b['version'])->get()->all(), $table);
        }
        foreach (['recipe_batch_quantities', 'recipe_batch_equipment', 'recipe_ingredients', 'recipe_batch_formats'] as $table) {
            $copies = DB::table($table)->where('recipe_version_id', $id)->get()->all();
            foreach ($before[$table] as $index => $row) {
                $old = (array) $row;
                $new = (array) $copies[$index];
                foreach (['id', 'recipe_version_id', 'format_id', 'recipe_ingredient_id', 'recipe_kitchen_equipment_id', 'component_id', 'manual_context', 'review_ack_hash'] as $key) {
                    unset($old[$key], $new[$key]);
                }
                if ($table === 'recipe_batch_equipment') {
                    $old['input_snapshot'] = json_decode($old['input_snapshot'], true);
                    unset($old['input_snapshot']['adequacy_ack']);
                    $new['input_snapshot'] = json_decode($new['input_snapshot'], true);
                }
                $this->assertEquals($old, $new, $table);
            }
        }
        $this->assertEquals($foods, DB::table('foods')->get()->all());
        $this->assertEquals($recipe, DB::table('recipes')->find($recipe->id));
        $preview = $this->editor->preview($this->actor, $id, $copy['revision'], $copy['payload']);
        $this->assertTrue($preview['evaluation']['rows'][$copy['payload']['rows'][0]['id']]['week']['review']);
        $this->assertTrue($preview['equipment'][$copy['payload']['equipment'][0]['id']]['week']['review']);
        try {
            DB::transaction(fn () => DB::select('SELECT recipe_batch_validate(?,true)', [$id]));
            $this->fail('SQL must reject copied review');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Manual needs review', $e->getMessage());
        }
        array_unshift($copy['payload']['directions'], 'Новий підготовчий крок');
        $copy = $this->commit($id, $copy, ack: true);
        $preview = $this->editor->preview($this->actor, $id, $copy['revision'], $copy['payload']);
        $this->assertSame('https://example.invalid/step.png', DB::table('recipe_directions')->where('recipe_version_id', $id)->where('direction_number', 2)->value('image_url'));
        $this->editor->publish($this->actor, $id, $copy['revision'], $preview['token']);
        $next = DB::table('recipe_versions')->find($id);
        $this->assertSame(2, $next->version_number);
        $this->assertNotSame($before['recipe_versions'][0]->food_id, $next->food_id);
        $this->assertEquals($before['recipe_versions'][0], DB::table('recipe_versions')->find($b['version']));
        foreach ($historical as $table => $records) {
            $this->assertEquals($records, DB::table($table)->orderBy('id')->get()->all(), $table);
        }
        foreach (['recipe_versions', 'recipe_ingredients', 'recipe_directions', 'recipe_batch_formats', 'recipe_batch_equipment'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->where($table === 'recipe_versions' ? 'id' : 'recipe_version_id', $b['version'])->delete());
                $this->fail('Published source must be immutable');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $third = $this->editor->createVersion($this->actor, $id, (string) $next->lock_version);
        $this->assertSame($id, DB::table('recipe_versions')->find($third)->source_recipe_version_id);
    }

    public function test_clone_stale_sources_require_explicit_refresh_and_keep_manual_pair(): void
    {
        $b = $this->publishedForClone();
        $source = $this->state($b);
        $snapshot = DB::table('recipe_ingredients')->where('id', $b['row'])->value('ingredient_snapshot');
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '124.00']);
        DB::table('ingredient_uoms')->where('ingredient_id', $b['ingredient'])->update(['grams_per_uom' => '420.00']);
        DB::table('kitchen_equipment')->where('id', $source['payload']['equipment'][0]['kitchen_equipment_id'])->update(['name' => 'Changed clone pan']);
        $id = $this->editor->createVersion($this->actor, $b['version'], $source['revision']);
        $copy = $this->editor->open($this->actor, $id);
        $this->assertSame($snapshot, DB::table('recipe_ingredients')->where('recipe_version_id', $id)->value('ingredient_snapshot'));
        $preview = $this->editor->preview($this->actor, $id, $copy['revision'], $copy['payload']);
        $this->assertNotEmpty($preview['diff']);
        $this->assertNotEmpty($preview['equipment_diff']);
        $this->denied(fn () => $this->commit($id, $copy), 'явно прийміть diff');
        $this->denied(fn () => $this->editor->publish($this->actor, $id, $copy['revision'], $preview['token']), 'Refresh');
        $this->assertSame($copy, $this->editor->open($this->actor, $id));
        $copy = $this->commit($id, $copy, refresh: true);
        $this->assertSame('3.00', $copy['payload']['rows'][0]['week']['quantity']);
        $this->assertSame($source['payload']['rows'][0]['week']['uom_id'], $copy['payload']['rows'][0]['week']['uom_id']);
        $this->assertSame('1260.00', DB::table('recipe_batch_quantities')->where('recipe_version_id', $id)->where('format_code', 'week')->value('quantity_grams'));
        $this->denied(fn () => $this->editor->save($this->actor, $id, $source['revision'], $copy['payload'], $preview['token']), 'Конфлікт');
        $this->assertSame($source, $this->state($b));
    }

    public function test_clone_rejects_draft_legacy_missing_stale_duplicate_and_revoked_access(): void
    {
        $b = $this->batch();
        $this->denied(fn () => $this->editor->createVersion($this->actor, $b['version'], $this->state($b)['revision']), 'опубліковану');
        $b = $this->publishedForClone();
        $state = $this->state($b);
        $this->denied(fn () => $this->editor->createVersion($this->actor, $b['version'], '0'), 'ревізією');
        $legacyRecipe = DB::table('recipes')->insertGetId(['title' => 'Legacy clone boundary']);
        $legacy = DB::table('recipe_versions')->insertGetId(['recipe_id' => $legacyRecipe, 'title' => 'Legacy']);
        $this->denied(fn () => $this->editor->createVersion($this->actor, $legacy, '0'), 'Legacy');
        DB::table('recipe_ingredients')->insert(['recipe_version_id' => $legacy, 'ingredient_id' => $b['ingredient'], 'quantity' => '150.00', 'quantity_grams' => '150.00', 'uom_id' => $b['g']]);
        $food = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
        DB::table('recipe_versions')->where('id', $legacy)->update(['status' => 'published', 'food_id' => $food, 'version_number' => 1, 'published_at' => now(), 'kcal_per_serving' => '185.18', 'protein_per_serving' => '30.00', 'fat_per_serving' => '4.50', 'carbs_per_serving' => '6.00']);
        $this->denied(fn () => $this->editor->createVersion($this->actor, $legacy, '0'), 'Legacy');
        try {
            $this->editor->createVersion($this->actor, 2147483647, '0');
            $this->fail('Missing source');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->editor->createVersion($this->actor, $b['version'], $state['revision']);
        $count = DB::table('recipe_versions')->count();
        $this->denied(fn () => $this->editor->createVersion($this->actor, $b['version'], $state['revision']), 'уже має чернетку');
        $this->assertSame($count, DB::table('recipe_versions')->count());
        try {
            DB::transaction(fn () => DB::table('recipe_versions')->insert(['recipe_id' => DB::table('recipe_versions')->find($b['version'])->recipe_id, 'title' => 'Duplicate bypass']));
            $this->fail('DB must independently reject a second draft');
        } catch (QueryException $e) {
            $this->assertStringContainsString('recipe_one_draft', $e->getMessage());
        }
        foreach (['flag', 'role'] as $case) {
            config(['recipes.batch_editor_enabled' => $case !== 'flag']);
            if ($case === 'role') {
                DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
            }
            try {
                $this->editor->createVersion($this->actor, $b['version'], $state['revision']);
                $this->fail('Access must be checked');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_clone_rolls_back_all_children_after_late_failure_and_filament_redirects_to_editable_copy(): void
    {
        $b = $this->publishedForClone();
        $state = $this->state($b);
        DB::unprepared("CREATE FUNCTION pg_temp.reject_clone_direction() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic late clone failure'; END $$; CREATE TRIGGER synthetic_clone_failure BEFORE INSERT ON recipe_directions FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_clone_direction();");
        $before = DB::table('recipe_versions')->count();
        try {
            $this->editor->createVersion($this->actor, $b['version'], $state['revision']);
            $this->fail('Expected injected failure');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Synthetic late clone failure', $e->getMessage());
        }
        $this->assertSame($before, DB::table('recipe_versions')->count());
        foreach (['recipe_ingredients', 'recipe_batch_quantities', 'recipe_batch_formats', 'recipe_batch_equipment'] as $table) {
            $this->assertSame(0, DB::table($table)->whereNotIn('recipe_version_id', DB::table('recipe_versions')->select('id'))->count());
        }
        DB::unprepared('DROP TRIGGER synthetic_clone_failure ON recipe_directions');
        $this->installAuthSchema();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $page = Livewire::withQueryParams(['version' => $b['version']])->test(RecipeBatches::class)->assertSee('Створити нову версію A/B/C')->call('createVersion')->assertHasNoErrors();
        $id = DB::table('recipe_versions')->where('source_recipe_version_id', $b['version'])->value('id');
        $page->assertRedirect(RecipeBatches::getUrl(['version' => $id]));
        Livewire::withQueryParams(['version' => $id])->test(RecipeBatches::class)->assertSet('boundary', '')->call('calculate')->call('saveDraft')->assertHasNoErrors();
    }

    public function test_create_get_and_preview_are_read_only_and_client_totals_are_ignored(): void
    {
        // g — synthetic fixture, не T1.5 seed.
        $this->batch();
        $id = $this->editor->create($this->actor);
        $state = $this->editor->open($this->actor, $id);
        $state['payload']['totals'] = ['kcal' => 999];
        $p = $this->editor->preview($this->actor, $id, $state['revision'], $state['payload']);
        $this->assertTrue($p['evaluation']['incomplete']);
        $this->assertSame('0.00', $p['evaluation']['portions']['A/1']['kcal']);
        $this->assertSame($state['revision'], $this->editor->open($this->actor, $id)['revision']);
        $this->assertSame(0, DB::table('foods')->where('food_type', 'recipe_version')->count());
    }

    public function test_manual_pair_cascade_reload_reset_and_revision_conflict(): void
    {
        $b = $this->batch();
        $state = $stale = $this->state($b);
        $r = &$state['payload']['rows'][0];
        $r['week']['mode'] = 'manual';
        $r['week']['quantity'] = '1000.00';
        $state = $this->commit($b['version'], $state, ack: true);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertSame('500.00', $p['evaluation']['rows'][$b['row']]['half_week']['quantity']);
        $this->assertFalse($p['evaluation']['rows'][$b['row']]['week']['review']);
        $state['payload']['rows'][0]['half_week']['mode'] = 'manual';
        $state['payload']['rows'][0]['half_week']['quantity'] = '600.00';
        $state = $this->commit($b['version'], $state, ack: true);
        $state['payload']['rows'][0]['quantity'] = '150.01';
        $state = $this->commit($b['version'], $state);
        $this->assertSame('1000.00', $state['payload']['rows'][0]['week']['quantity']);
        $this->assertSame('600.00', $state['payload']['rows'][0]['half_week']['quantity']);
        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) n FROM recipe_batch_quantities WHERE recipe_version_id=? AND manual_context IS DISTINCT FROM recipe_batch_context(recipe_ingredient_id,format_code)', [$b['version']])->n);
        $this->denied(fn () => $this->commit($b['version'], $stale), 'Конфлікт');
        $state['payload']['rows'][0]['week']['mode'] = 'auto';
        $state = $this->commit($b['version'], $state);
        $this->assertSame('1050.07', $state['payload']['rows'][0]['week']['quantity']);
        $this->assertSame('600.00', $state['payload']['rows'][0]['half_week']['quantity']);
    }

    public function test_pending_manual_round_trip_omits_nutrition_and_remaps_tokens(): void
    {
        $b = $this->batch();
        $state = $this->state($b);
        $state['payload']['rows'][0]['week']['mode'] = 'manual';
        $state['payload']['rows'][0]['week']['quantity'] = '';
        $state['payload']['directions'] = ['Взяти {{ingredient:'.$b['row'].':quantity}}'];
        $state = $this->commit($b['version'], $state);
        $this->assertSame('pending:row-'.$b['row'], $state['payload']['rows'][0]['id']);
        $this->assertSame('', $state['payload']['rows'][0]['week']['quantity']);
        $this->assertSame('manual', $state['payload']['rows'][0]['week']['mode']);
        $this->assertSame(0, DB::table('recipe_ingredients')->where('recipe_version_id', $b['version'])->count());
        $state['payload']['rows'][0]['week']['quantity'] = '1000.00';
        $state = $this->commit($b['version'], $state);
        $new = $state['payload']['rows'][0]['id'];
        $this->assertNotSame((string) $b['row'], $new);
        $this->assertSame(['Взяти {{ingredient:'.$new.':quantity}}'], $state['payload']['directions']);
        $this->assertSame('1000.00', $state['payload']['rows'][0]['week']['quantity']);
    }

    public function test_refresh_requires_signed_current_diff_and_manual_review_is_explicit(): void
    {
        $b = $this->batch();
        $state = $this->state($b);
        $state['payload']['rows'][0]['week']['mode'] = 'manual';
        $state['payload']['rows'][0]['week']['quantity'] = '1000.00';
        $state = $this->commit($b['version'], $state, ack: true);
        $old = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '124.00']);
        $before = DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->get()->toJson();
        $this->denied(fn () => $this->editor->save($this->actor, $b['version'], $state['revision'], $state['payload'], $old['token'], true), 'застарів');
        $this->denied(fn () => $this->commit($b['version'], $state), 'явно прийміть diff');
        $this->assertSame($before, DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->get()->toJson());
        $state = $this->commit($b['version'], $state, refresh: true);
        $this->assertSame('1000.00', $state['payload']['rows'][0]['week']['quantity']);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertTrue($p['evaluation']['rows'][$b['row']]['week']['review']);
        $state = $this->commit($b['version'], $state, ack: true);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertFalse($p['evaluation']['rows'][$b['row']]['week']['review']);
    }

    public function test_bad_or_cross_version_inputs_and_tampered_preview_never_write(): void
    {
        $b = $this->batch();
        $foreign = $this->batch();
        $state = $this->state($b);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $state['payload']['title'] = 'Tampered';
        $this->denied(fn () => $this->editor->save($this->actor, $b['version'], $state['revision'], $state['payload'], $p['token']), 'застарів');
        $state['payload']['rows'][0]['id'] = (string) $foreign['row'];
        $this->denied(fn () => $this->commit($b['version'], $state), 'Чужий');
        $state = $this->state($b);
        $state['payload']['rows'][0]['week']['mode'] = 'manual';
        $state['payload']['rows'][0]['week']['uom_id'] = '32000';
        $this->denied(fn () => $this->commit($b['version'], $state), 'конверсії');
        $this->assertSame($this->state($b)['revision'], $state['revision']);
    }

    public function test_synthetic_can_precision_inherited_policy_and_refresh_factor(): void
    {
        $b = $this->batch();
        $can = DB::table('uoms')->insertGetId(['code' => 'test-can', 'label' => 'Synthetic can', 'kind' => 'count']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $can, 'grams_per_uom' => '33.33']);
        $state = $this->state($b);
        $r = &$state['payload']['rows'][0];
        $r['week'] = ['mode' => 'manual', 'quantity' => '0.25', 'uom_id' => (string) $can, 'rounding' => 'exact', 'step' => '0.01'];
        $r['auto_policies'] = ['test-can' => ['rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true]];
        $state = $this->commit($b['version'], $state, refresh: true);
        $this->assertSame('8.33', DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'week')->value('quantity_grams'));
        $this->assertSame((string) $can, $state['payload']['rows'][0]['half_week']['uom_id']);
        $this->assertSame('0.50', $state['payload']['rows'][0]['half_week']['quantity']);
        DB::table('ingredient_uoms')->where('ingredient_id', $b['ingredient'])->where('uom_id', $can)->update(['grams_per_uom' => '40.00']);
        $state = $this->commit($b['version'], $state, refresh: true);
        $this->assertSame('0.25', $state['payload']['rows'][0]['week']['quantity']);
        $this->assertSame('10.00', DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'week')->value('quantity_grams'));
    }

    public function test_publication_atomic_tokens_equipment_and_immutability(): void
    {
        $b = $this->batch();
        $category = DB::table('recipe_categories')->insertGetId(['name' => 'Synthetic category']);
        $ic = DB::table('ingredient_categories')->insertGetId(['name' => 'Synthetic ingredient category']);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['ingredient_category_id' => $ic]);
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Synthetic pot']);
        $state = $this->state($b);
        $state['payload']['category_id'] = (string) $category;
        $state['payload']['directions'] = ['Взяти {{ingredient:'.$b['row'].':quantity}}'];
        $state['payload']['equipment'] = [['id' => 'new-pot', 'kitchen_equipment_id' => (string) $tool, 'min_value' => '2.00', 'uom_id' => (string) $b['g'], 'scaling_mode' => 'proportional', 'tip' => 'Перевірити місткість']];
        $state = $this->commit($b['version'], $state, refresh: true, ack: true);
        $this->assertSame(3, DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->count());
        $this->assertSame(['2.00', '14.00', '7.00'], DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->orderBy('format_id')->pluck('min_value')->all());
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->editor->publish($this->actor, $b['version'], $state['revision'], $p['token']);
        $this->assertSame('published', DB::table('recipe_versions')->where('id', $b['version'])->value('status'));
        $this->assertSame(1, DB::table('foods')->where('food_type', 'recipe_version')->count());
        $this->denied(fn () => $this->editor->publish($this->actor, $b['version'], $state['revision'], $p['token']), 'Конфлікт');
        $this->assertSame(1, DB::table('foods')->where('food_type', 'recipe_version')->count());
    }

    public function test_failed_publish_and_late_equipment_failure_roll_back_all_changes(): void
    {
        $b = $this->batch();
        $state = $this->state($b);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->denied(fn () => $this->editor->publish($this->actor, $b['version'], $state['revision'], $p['token']), 'завершіть');
        $this->assertSame(0, DB::table('foods')->where('food_type', 'recipe_version')->count());
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Synthetic']);
        $state['payload']['equipment'] = [['id' => 'new-fault', 'kitchen_equipment_id' => (string) $tool, 'min_value' => '', 'uom_id' => '', 'scaling_mode' => 'fixed', 'tip' => '']];
        $state = $this->commit($b['version'], $state);
        DB::unprepared("CREATE FUNCTION synthetic_equipment_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic equipment fault'; END \$\$; CREATE TRIGGER synthetic_equipment_failure BEFORE UPDATE ON recipe_batch_equipment FOR EACH ROW EXECUTE FUNCTION synthetic_equipment_failure();");
        $state = $this->state($b);
        $state['payload']['title'] = 'Must roll back';
        try {
            $this->commit($b['version'], $state);
            $this->fail('Equipment fault must roll back');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Synthetic equipment fault', $e->getMessage());
        }
        $this->assertSame('Synthetic batch', DB::table('recipe_versions')->where('id', $b['version'])->value('title'));
        $this->assertSame($state['revision'], $this->state($b)['revision']);
    }

    public function test_unfinished_decimal_and_zero_are_pending_and_new_row_tokens_remap_with_component(): void
    {
        $b = $this->batch();
        $state = $this->state($b);
        foreach (['1.', '0', '0.001'] as $raw) {
            $state['payload']['rows'][0]['quantity'] = $raw;
            $state = $this->commit($b['version'], $state);
            $this->assertSame($raw, $state['payload']['rows'][0]['quantity']);
            $this->assertSame(0, DB::table('recipe_ingredients')->where('recipe_version_id', $b['version'])->count());
        }
        $key = $state['payload']['rows'][0]['id'];
        $state['payload']['components'] = [['id' => 'new-base', 'title' => 'Основа']];
        $state['payload']['rows'][0]['component_id'] = 'new-base';
        $state['payload']['rows'][0]['quantity'] = '150.00';
        $state['payload']['directions'] = ['Взяти {{ingredient:'.$key.':grams}}'];
        $state = $this->commit($b['version'], $state);
        $row = $state['payload']['rows'][0];
        $this->assertSame($state['payload']['components'][0]['id'], $row['component_id']);
        $this->assertSame(['Взяти {{ingredient:'.$row['id'].':grams}}'], $state['payload']['directions']);
    }

    public function test_pending_manual_retains_frozen_source_until_explicit_refresh_on_completion(): void
    {
        $b = $this->batch();
        $state = $this->state($b);
        $state['payload']['rows'][0]['week']['mode'] = 'manual';
        $state['payload']['rows'][0]['week']['quantity'] = '1000.00';
        $state = $this->commit($b['version'], $state, ack: true);
        $state['payload']['rows'][0]['quantity'] = '';
        $state = $this->commit($b['version'], $state);
        // Повторний pending Save не губить frozen metadata ручного override.
        $state = $this->commit($b['version'], $state);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '124.00']);
        $state['payload']['rows'][0]['quantity'] = '150.00';
        $this->denied(fn () => $this->commit($b['version'], $state), 'явно прийміть diff');
        $state = $this->commit($b['version'], $state, refresh: true);
        $this->assertSame('1000.00', $state['payload']['rows'][0]['week']['quantity']);
    }

    public function test_pending_auto_keeps_server_provenance_after_manual_reset_and_repeated_saves(): void
    {
        $b = $this->batch();
        $state = $this->state($b);
        $state['payload']['rows'][0]['week']['mode'] = 'manual';
        $state['payload']['rows'][0]['week']['quantity'] = '1000.00';
        $state = $this->commit($b['version'], $state, ack: true);
        $hash = DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$b['ingredient']])->hash;
        $state['payload']['rows'][0]['quantity'] = '';
        $state['payload']['rows'][0]['week']['mode'] = 'auto';
        $state['payload']['rows'][0]['week']['input_snapshot'] = ['source_hash' => 'forged'];
        $state = $this->commit($b['version'], $state);
        $state = $this->commit($b['version'], $state);
        $pending = json_decode(DB::table('recipe_versions')->where('id', $b['version'])->value('draft_pending_rows'), true)[0];
        foreach (['week', 'half_week'] as $code) {
            $this->assertSame($hash, $pending['overrides'][$code]['input_snapshot']['source_hash']);
            $this->assertEquals(['schema_version' => 1, 'mode' => 'auto', 'quantity_grams' => $code === 'week' ? '1000.00' : '500.00'], $pending['overrides'][$code]['input_snapshot']['pending_state']);
            $this->assertSame('auto', $state['payload']['rows'][0][$code]['mode']);
        }
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '124.00']);
        $state['payload']['rows'][0]['quantity'] = '150.00';
        $this->denied(fn () => $this->commit($b['version'], $state), 'явно прийміть diff');
        $state = $this->commit($b['version'], $state, refresh: true);
        $this->assertSame('1050.00', $state['payload']['rows'][0]['week']['quantity']);
    }

    public function test_pending_auto_envelope_is_bounded_and_new_rows_have_no_fabricated_source(): void
    {
        $b = $this->batch();
        $id = $this->editor->create($this->actor);
        $state = $this->editor->open($this->actor, $id);
        $row = $this->state($b)['payload']['rows'][0];
        $row['id'] = 'pending:new-auto';
        $row['quantity'] = '';
        $row['auto_policies'] = ['untrusted' => str_repeat('x', 9000)];
        $state['payload']['rows'] = [$row];
        $this->denied(fn () => $this->commit($id, $state), '8 KiB');
        $this->assertSame('[]', DB::table('recipe_versions')->where('id', $id)->value('draft_pending_rows'));
        $state['payload']['rows'][0]['auto_policies'] = [];
        $state = $this->commit($id, $state);
        $pending = json_decode(DB::table('recipe_versions')->where('id', $id)->value('draft_pending_rows'), true)[0];
        $this->assertArrayNotHasKey('source_hash', $pending['overrides']['week']['input_snapshot']);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '124.00']);
        $state['payload']['rows'][0]['quantity'] = '150.00';
        $state = $this->commit($id, $state);
        $this->assertSame('auto', $state['payload']['rows'][0]['week']['mode']);
    }

    private function equipmentState(array $b, string $kind = 'count', string $value = '1.00'): array
    {
        $unit = DB::table('uoms')->insertGetId(['code' => 'test-tool-'.$kind, 'label' => 'Synthetic '.$kind, 'kind' => $kind]);
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Synthetic pan']);
        $state = $this->state($b);
        $state['payload']['equipment'][] = ['id' => 'new-tool', 'kitchen_equipment_id' => (string) $tool, 'min_value' => $value,
            'uom_id' => (string) $unit, 'scaling_mode' => 'proportional', 'tip' => 'Base tip'];

        return $state;
    }

    public function test_equipment_stable_ids_manual_review_acknowledgement_and_reset(): void
    {
        $b = $this->batch();
        $state = $this->equipmentState($b);
        $state['payload']['rows'][0]['quantity'] = '150';
        $state['payload']['equipment'][0]['min_value'] = '1';
        $state['payload']['directions'] = ['Взяти {{equipment:new-tool:quantity}}'];
        $state = $this->commit($b['version'], $state, ack: true);
        $id = $state['payload']['equipment'][0]['id'];
        $this->assertSame(['Взяти {{equipment:'.$id.':quantity}}'], $state['payload']['directions']);
        $this->assertSame('4.00', $state['payload']['equipment'][0]['half_week']['min_value']);
        $state['payload']['equipment'][0]['week'] = ['mode' => 'manual', 'min_value' => '2.00', 'uom_id' => $state['payload']['equipment'][0]['uom_id'], 'tip' => 'Manual tip'];
        $state = $this->commit($b['version'], $state, ack: true);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertFalse($p['equipment'][$id]['week']['review']);
        $state['payload']['rows'][0]['quantity'] = '160.00';
        $state['payload']['equipment'][0]['min_value'] = '2.00';
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertTrue($p['equipment'][$id]['week']['review']);
        $state = $this->commit($b['version'], $state);
        $this->assertSame($id, $state['payload']['equipment'][0]['id']);
        $this->assertSame('2.00', $state['payload']['equipment'][0]['week']['min_value']);
        $this->assertSame('Manual tip', $state['payload']['equipment'][0]['week']['tip']);
        $state = $this->commit($b['version'], $state, ack: true);
        $state = $this->commit($b['version'], $state);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertFalse($p['equipment'][$id]['week']['review']);
        $state['payload']['equipment'][0]['week']['mode'] = 'auto';
        $state = $this->commit($b['version'], $state);
        $this->assertSame('14.00', $state['payload']['equipment'][0]['week']['min_value']);
        $this->assertSame($id, $state['payload']['equipment'][0]['id']);
        $this->assertSame(3, DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->count());
    }

    public function test_equipment_nullable_volume_fixed_and_manual_uom_survive_reload(): void
    {
        $b = $this->batch();
        $state = $this->equipmentState($b, 'volume', '1.25');
        $state = $this->commit($b['version'], $state, ack: true);
        $this->assertSame('4.38', $state['payload']['equipment'][0]['half_week']['min_value']);
        $state['payload']['equipment'][0]['scaling_mode'] = 'fixed';
        $state = $this->commit($b['version'], $state);
        $this->assertSame('1.25', $state['payload']['equipment'][0]['half_week']['min_value']);
        $state['payload']['equipment'][0]['week'] = ['mode' => 'manual', 'min_value' => '', 'uom_id' => '', 'tip' => 'No minimum'];
        $state['payload']['equipment'][0]['min_value'] = '';
        $state = $this->commit($b['version'], $state, ack: true);
        foreach (['single', 'week', 'half_week'] as $code) {
            $this->assertSame('', $state['payload']['equipment'][0][$code]['min_value']);
        }
        $state['payload']['equipment'][0]['min_value'] = '2.00';
        $state = $this->commit($b['version'], $state);
        $this->assertSame('', $state['payload']['equipment'][0]['week']['min_value']);
        $this->assertSame('', $state['payload']['equipment'][0]['week']['uom_id']);
        $this->assertSame('No minimum', $state['payload']['equipment'][0]['week']['tip']);
    }

    public function test_equipment_tokens_deleted_draft_and_publish_rollback(): void
    {
        $b = $this->batch();
        $state = $this->equipmentState($b);
        $category = DB::table('recipe_categories')->insertGetId(['name' => 'Synthetic recipe category']);
        $ingredientCategory = DB::table('ingredient_categories')->insertGetId(['name' => 'Synthetic ingredient category']);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['ingredient_category_id' => $ingredientCategory]);
        $state['payload']['category_id'] = (string) $category;
        $state['payload']['directions'] = ['{{equipment:new-tool:quantity}} 20 хв при 180°C'];
        $state = $this->commit($b['version'], $state, refresh: true, ack: true);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertStringContainsString('7.00', RecipeTokens::render($state['payload']['directions'][0], $p['evaluation']['rows'], 'week', $p['equipment']));
        $state['payload']['equipment'] = [];
        $state = $this->commit($b['version'], $state);
        $foods = DB::table('foods')->count();
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->denied(fn () => $this->editor->publish($this->actor, $b['version'], $state['revision'], $p['token']), 'видалений');
        $this->assertSame($foods, DB::table('foods')->count());
        $this->assertSame('draft', DB::table('recipe_versions')->where('id', $b['version'])->value('status'));
    }

    public function test_equipment_publication_requires_current_adequacy_and_is_atomic(): void
    {
        $b = $this->batch();
        $state = $this->equipmentState($b);
        $category = DB::table('recipe_categories')->insertGetId(['name' => 'Synthetic recipe category']);
        $ingredientCategory = DB::table('ingredient_categories')->insertGetId(['name' => 'Synthetic ingredient category']);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['ingredient_category_id' => $ingredientCategory]);
        $state['payload']['category_id'] = (string) $category;
        $state['payload']['directions'] = ['Готувати {{equipment:new-tool:quantity}}'];
        $state = $this->commit($b['version'], $state, refresh: true);
        $foods = DB::table('foods')->count();
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->denied(fn () => $this->editor->publish($this->actor, $b['version'], $state['revision'], $p['token']), 'достатність');
        try {
            DB::transaction(fn () => DB::select('SELECT recipe_batch_validate(?,true)', [$b['version']]));
            $this->fail('SQL must independently require adequacy');
        } catch (QueryException $e) {
            $this->assertStringContainsString('adequacy', $e->getMessage());
        }
        $this->assertSame($foods, DB::table('foods')->count());
        $this->assertSame($state['revision'], $this->state($b)['revision']);
        $state = $this->commit($b['version'], $state, ack: true);
        $state['payload']['rows'][0]['week']['mode'] = 'manual';
        $state['payload']['rows'][0]['week']['quantity'] = '1000.00';
        $state = $this->commit($b['version'], $state);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertTrue($p['equipment'][$state['payload']['equipment'][0]['id']]['week']['review']);
        $state = $this->commit($b['version'], $state, ack: true);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->editor->publish($this->actor, $b['version'], $state['revision'], $p['token']);
        $this->assertSame('published', $this->state($b)['status']);
        $this->assertSame($foods + 1, DB::table('foods')->count());
    }

    public function test_unknown_and_malformed_tokens_save_as_draft_but_never_publish(): void
    {
        $b = $this->batch();
        $category = DB::table('recipe_categories')->insertGetId(['name' => 'Synthetic recipe category']);
        $state = $this->state($b);
        $state['payload']['category_id'] = (string) $category;
        foreach (['{{timer:1:seconds}}', '{{equipment:99:quantity}', '{{ingredient:99:grams}}'] as $text) {
            $state['payload']['directions'] = [$text];
            $state = $this->commit($b['version'], $state);
            $foods = DB::table('foods')->count();
            $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
            try {
                $this->editor->publish($this->actor, $b['version'], $state['revision'], $p['token']);
                $this->fail('Token must block publication');
            } catch (ValidationException) {
                $this->assertSame($foods, DB::table('foods')->count());
                $this->assertSame($state, $this->state($b));
            }
        }
    }

    public function test_equipment_catalog_requires_explicit_refresh_and_new_ack(): void
    {
        $b = $this->batch();
        $state = $this->equipmentState($b);
        $state = $this->commit($b['version'], $state, ack: true);
        $tool = $state['payload']['equipment'][0];
        DB::table('kitchen_equipment')->where('id', $tool['kitchen_equipment_id'])->update(['name' => 'Changed synthetic pan']);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertNotEmpty($p['equipment_diff']);
        $this->denied(fn () => $this->commit($b['version'], $state), 'явно прийміть diff');
        $state = $this->commit($b['version'], $state, refresh: true);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertTrue($p['equipment'][$tool['id']]['week']['review']);
        $state = $this->commit($b['version'], $state, ack: true);
        $p = $this->editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertFalse($p['equipment'][$tool['id']]['week']['review']);
    }

    public function test_database_rejects_fractional_auto_count_equipment(): void
    {
        $b = $this->batch();
        $state = $this->commit($b['version'], $this->equipmentState($b), ack: true);
        try {
            DB::transaction(function () use ($b) {
                DB::table('recipe_batch_equipment')->where('format_id', $b['formats']['half_week'])->update(['min_value' => '3.50']);
                DB::select('SELECT recipe_batch_validate(?)', [$b['version']]);
            });
            $this->fail('Fractional count must fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Auto equipment mismatch', $e->getMessage());
        }
        $this->assertSame($state, $this->state($b));
    }

    public function test_auto_b_presets_and_c_follow_actual_units_with_confirmed_steps(): void
    {
        $b = $this->batch();
        $state = $this->state($b);
        $state['payload']['rows'][0]['quantity'] = '150.00';
        $state['payload']['rows'][0]['week'] += ['confirmed' => true];
        $state['payload']['rows'][0]['week']['rounding'] = 'nearest';
        $state['payload']['rows'][0]['week']['step'] = '100.00';
        $state['payload']['rows'][0]['week']['confirmed'] = true;
        $state = $this->commit($b['version'], $state);
        $this->assertSame('1100.00', $state['payload']['rows'][0]['week']['quantity']);
        $this->assertSame('550.00', $state['payload']['rows'][0]['half_week']['quantity']);
        $can = DB::table('uoms')->insertGetId(['code' => 'test-pack', 'label' => 'Synthetic pack', 'kind' => 'count']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $can, 'grams_per_uom' => '400.00']);
        $r = &$state['payload']['rows'][0];
        $r['week'] = ['mode' => 'auto', 'quantity' => '', 'uom_id' => (string) $can, 'rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true];
        $r['auto_policies']['test-pack'] = ['rounding' => 'ceil', 'step' => '0.25', 'confirmed' => true];
        unset($r);
        $state = $this->commit($b['version'], $state, refresh: true);
        $this->assertSame('3.00', $state['payload']['rows'][0]['week']['quantity']);
        $this->assertSame('1.50', $state['payload']['rows'][0]['half_week']['quantity']);
        $this->assertSame((string) $can, $state['payload']['rows'][0]['half_week']['uom_id']);
        $state['payload']['rows'][0]['week']['confirmed'] = false;
        $this->denied(fn () => $this->commit($b['version'], $state), 'policy');
    }

    public function test_feature_flag_and_current_database_role_are_required(): void
    {
        config(['recipes.batch_editor_enabled' => false]);
        try {
            $this->editor->create($this->actor);
            $this->fail('flag');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        config(['recipes.batch_editor_enabled' => true]);
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
        try {
            $this->editor->create($this->actor);
            $this->fail('stale role');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_legacy_boundary_is_explicit_and_livewire_page_uses_authorized_commands(): void
    {
        $recipe = DB::table('recipes')->insertGetId(['title' => 'Legacy']);
        $legacy = DB::table('recipe_versions')->insertGetId(['recipe_id' => $recipe, 'title' => 'Legacy']);
        $this->assertArrayHasKey('boundary', $this->editor->open($this->actor, $legacy));
        $this->installAuthSchema();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $b = $this->batch();
        Livewire::withQueryParams(['version' => $b['version']])->test(RecipeBatches::class)->assertSet('revision', $this->state($b)['revision'])
            ->call('addRow')->call('calculate')->assertHasNoErrors()->call('saveDraft')->assertHasNoErrors()
            ->assertSee('Неповний розрахунок');
    }
}
