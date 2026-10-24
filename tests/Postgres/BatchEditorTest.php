<?php

namespace Tests\Postgres;

use App\Filament\Pages\RecipeBatches;
use App\Models\User;
use App\Services\Recipes\BatchEditor;
use Filament\Facades\Filament;
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
        $state = $this->commit($b['version'], $state, refresh: true);
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
        $state['payload']['equipment'] = [['id' => 'new', 'kitchen_equipment_id' => (string) $tool, 'min_value' => '', 'uom_id' => '', 'scaling_mode' => 'fixed', 'tip' => '']];
        $state = $this->commit($b['version'], $state);
        DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->update(['mode' => 'manual', 'manual_context' => '{}']);
        $state = $this->state($b);
        $state['payload']['title'] = 'Must roll back';
        $this->denied(fn () => $this->commit($b['version'], $state), 'Manual equipment');
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
