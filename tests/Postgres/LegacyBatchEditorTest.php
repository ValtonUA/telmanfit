<?php

namespace Tests\Postgres;

use App\Filament\Pages\RecipeBatches;
use App\Models\User;
use App\Services\Recipes\BatchEditor;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Recipes\LegacyFixture;

class LegacyBatchEditorTest extends PostgresTestCase
{
    use LegacyFixture;

    private BatchEditor $editor;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(database_path('migrations/2026_10_24_0[123]0000_*.php')) as $file) {
            (require $file)->up();
        }
        config(['recipes.batch_editor_enabled' => true]);
        $this->editor = app(BatchEditor::class);
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Legacy synthetic author', 'role' => 'admin']));
    }

    private function revision(int $id): string
    {
        return (string) DB::table('recipe_versions')->where('id', $id)->value('lock_version');
    }

    private function snapshot(): string
    {
        $state = [];
        foreach (['recipes', 'recipe_versions', 'recipe_ingredients', 'recipe_components', 'recipe_directions', 'recipe_kitchen_equipment', 'recipe_batch_formats', 'recipe_batch_quantities', 'recipe_batch_equipment', 'foods', 'meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments'] as $table) {
            $state[$table] = DB::table($table)->orderByRaw('1')->get()->all();
        }

        return hash('sha256', json_encode($state));
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

    public static function kinds(): array
    {
        return ['draft' => [false], 'published' => [true]];
    }

    #[DataProvider('kinds')]
    public function test_conversion_preserves_history_and_roundtrips_through_save_refresh_review_publish(bool $published): void
    {
        $b = $this->legacy($published);
        $id = $b['version'];
        $original = DB::table('recipe_versions')->find($id);
        $row = DB::table('recipe_ingredients')->find($b['row']);
        $component = DB::table('recipe_components')->find($b['component']);
        $direction = DB::table('recipe_directions')->where('recipe_version_id', $id)->first();
        $foodCount = DB::table('foods')->count();
        if ($published) {
            $serving = DB::table('uoms')->where('code', 'serving')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'serving', 'label' => 'порція', 'kind' => 'serving']);
            $plan = DB::table('meal_plans')->insertGetId(['title' => 'Legacy historical plan']);
            $planVersion = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
            $type = DB::table('meal_types')->insertGetId(['code' => 'legacy-meal', 'title' => 'Meal']);
            for ($day = 1; $day <= 7; $day++) {
                $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $planVersion, 'day_number' => $day]);
                $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $dayId, 'meal_type_id' => $type]);
                DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $original->food_id, 'quantity' => '1.00', 'uom_id' => $serving]);
            }
            DB::table('meal_plan_versions')->where('id', $planVersion)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
            $client = DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]);
            DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $planVersion, 'start_date' => '2026-01-01']);
        }
        $history = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments'] as $table) {
            $history[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '120.00']);
        DB::table('ingredient_uoms')->where('ingredient_id', $b['ingredient'])->update(['grams_per_uom' => '32.00']);
        $before = $this->snapshot();
        $p = $this->editor->previewLegacy($this->actor, $id, $this->revision($id));
        $this->assertSame($before, $this->snapshot(), 'Preview read-only');
        $this->assertTrue($p['changes'][$b['row']]['mismatch']);
        $this->assertSame('180.00', $p['nutrition']['kcal']['current_A']);
        $this->denied(fn () => $this->editor->convertLegacy($this->actor, $id, $this->revision($id), $p['token'], false, true), 'Явно');
        $new = $this->editor->convertLegacy($this->actor, $id, $this->revision($id), $p['token'], true, true);
        $this->assertSame($foodCount, DB::table('foods')->count());
        $this->assertSame($published, $new !== $id);
        $v = DB::table('recipe_versions')->find($new);
        $this->assertSame($b['recipe'], $v->recipe_id);
        $this->assertSame('1.00', $v->servings_count);
        $this->assertNull($v->food_id);
        $this->assertSame(1, DB::table('recipe_versions')->where('recipe_id', $b['recipe'])->where('status', 'draft')->count());
        $a = DB::table('recipe_ingredients')->where('recipe_version_id', $new)->first();
        $this->assertSame('150.00', $a->quantity);
        $this->assertSame($b['g'], $a->uom_id);
        $this->assertSame(9, $a->sort_order);
        $this->assertSame('1050.00', DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $a->id)->where('format_code', 'week')->value('quantity'));
        $this->assertSame('525.00', DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $a->id)->where('format_code', 'half_week')->value('quantity'));
        $proof = json_decode(DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $a->id)->where('format_code', 'week')->value('input_snapshot'), true)['legacy_source'];
        $this->assertEquals($row->quantity, $proof['quantity']);
        $this->assertSame($row->uom_id, $proof['uom_id']);
        $c = DB::table('recipe_components')->where('recipe_version_id', $new)->first();
        $this->assertSame($component->title, $c->title);
        $this->assertSame($component->sort_order, $c->sort_order);
        $state = $this->editor->open($this->actor, $new);
        $this->assertStringContainsString('{{ingredient:'.$a->id.':grams}}', $state['payload']['directions'][0]);
        $this->assertSame($direction->image_url, DB::table('recipe_directions')->where('recipe_version_id', $new)->value('image_url'));
        $hash = $this->snapshot();
        $this->assertSame($state, $this->editor->open($this->actor, $new));
        $this->assertSame($hash, $this->snapshot());
        $p = $this->editor->preview($this->actor, $new, $state['revision'], $state['payload']);
        $this->denied(fn () => $this->editor->publish($this->actor, $new, $state['revision'], $p['token']), 'достатність');
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['protein_per_100g' => '11.00']);
        $p = $this->editor->preview($this->actor, $new, $state['revision'], $state['payload']);
        $this->denied(fn () => $this->editor->save($this->actor, $new, $state['revision'], $state['payload'], $p['token']), 'Refresh');
        $this->editor->save($this->actor, $new, $state['revision'], $state['payload'], $p['token'], true, true);
        $this->assertSame($proof, json_decode(DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $a->id)->where('format_code', 'week')->value('input_snapshot'), true)['legacy_source']);
        $state = $this->editor->open($this->actor, $new);
        $p = $this->editor->preview($this->actor, $new, $state['revision'], $state['payload']);
        $this->editor->publish($this->actor, $new, $state['revision'], $p['token']);
        $this->assertSame($foodCount + 1, DB::table('foods')->count());
        if ($published) {
            $this->assertEquals($original, DB::table('recipe_versions')->find($id));
            $this->assertEquals($row, DB::table('recipe_ingredients')->find($b['row']));
            $this->assertEquals($direction, DB::table('recipe_directions')->where('recipe_version_id', $id)->first());
            $this->assertNotSame($original->food_id, DB::table('recipe_versions')->find($new)->food_id);
        }
        foreach ($history as $table => $records) {
            $this->assertEquals($records, DB::table($table)->orderBy('id')->get()->all(), $table);
        }
    }

    public function test_stale_catalog_revision_authorization_and_duplicate_draft_are_atomic(): void
    {
        $b = $this->legacy();
        $id = $b['version'];
        $rev = $this->revision($id);
        $p = $this->editor->previewLegacy($this->actor, $id, $rev);
        DB::table('recipe_directions')->where('recipe_version_id', $id)->update(['image_url' => 'https://example.invalid/changed.png']);
        $hash = $this->snapshot();
        $this->denied(fn () => $this->editor->convertLegacy($this->actor, $id, $rev, $p['token'], true, true), 'ревізій');
        $this->assertSame($hash, $this->snapshot());
        $rev = $this->revision($id);
        $p = $this->editor->previewLegacy($this->actor, $id, $rev);
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '121.00']);
        $this->denied(fn () => $this->editor->convertLegacy($this->actor, $id, $rev, $p['token'], true, true), 'застарів');
        $this->assertSame($hash, $this->snapshot());
        foreach (['client', 'coach', 'revoked', 'flag'] as $case) {
            $actor = $case === 'revoked' || $case === 'flag' ? $this->actor : User::findOrFail(DB::table('users')->insertGetId(['name' => 'Denied synthetic', 'role' => $case]));
            if ($case === 'revoked') {
                DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
            }
            config(['recipes.batch_editor_enabled' => $case !== 'flag']);
            try {
                $this->editor->convertLegacy($actor, $id, $rev, $p['token'], true, true);
                $this->fail('Неавторизована конвертація');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
        config(['recipes.batch_editor_enabled' => true]);
        $b = $this->legacy(true);
        $id = $b['version'];
        $p = $this->editor->previewLegacy($this->actor, $id, $this->revision($id));
        DB::table('recipe_versions')->insert(['recipe_id' => $b['recipe'], 'title' => 'Existing draft']);
        $hash = $this->snapshot();
        $this->denied(fn () => $this->editor->convertLegacy($this->actor, $id, $this->revision($id), $p['token'], true, true), 'уже має чернетку');
        $this->assertSame($hash, $this->snapshot());
    }

    public static function invalidCases(): array
    {
        return array_map(fn ($s) => [$s], ['conversion', 'conversion_nan', 'grams_nan', 'servings_nan', 'nutrition_nan', 'grams', 'tokens', 'empty', 'overflow', 'snapshot']);
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_and_ambiguous_sources_remain_unchanged(string $case): void
    {
        $b = $this->legacy();
        match ($case) {
            'conversion_nan' => DB::table('ingredient_uoms')->where('ingredient_id', $b['ingredient'])->update(['grams_per_uom' => 'NaN']),
            'grams_nan' => DB::table('recipe_ingredients')->where('id', $b['row'])->update(['quantity_grams' => 'NaN']),
            'servings_nan' => DB::table('recipe_versions')->where('id', $b['version'])->update(['servings_count' => 'NaN']),
            'nutrition_nan' => DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => 'NaN']),
            'conversion' => DB::table('ingredient_uoms')->where('ingredient_id', $b['ingredient'])->delete(),
            'grams' => DB::table('recipe_ingredients')->where('id', $b['row'])->update(['uom_id' => $b['g']]),
            'tokens' => DB::table('recipe_directions')->where('recipe_version_id', $b['version'])->update(['direction_text' => '{{ingredient:999999:grams}}']),
            'empty' => DB::table('recipe_directions')->where('recipe_version_id', $b['version'])->delete(),
            'overflow' => DB::table('recipe_ingredients')->where('id', $b['row'])->update(['quantity_grams' => '99999.99']),
            'snapshot' => DB::table('recipe_ingredients')->where('id', $b['row'])->update(['ingredient_snapshot' => json_encode(['schema_version' => 1, 'name' => 'Ambiguous', 'kcal' => '1.00', 'protein' => '1.00', 'fat' => '1.00', 'carbs' => '1.00'])]),
        };
        $hash = $this->snapshot();
        try {
            $this->editor->previewLegacy($this->actor, $b['version'], $this->revision($b['version']));
            $this->fail('Невалідне джерело');
        } catch (ValidationException) {
            $this->assertSame($hash, $this->snapshot());
        }
    }

    #[DataProvider('kinds')]
    public function test_late_failure_rolls_back_every_row_and_food(bool $published): void
    {
        $b = $this->legacy($published);
        $id = $b['version'];
        $p = $this->editor->previewLegacy($this->actor, $id, $this->revision($id));
        DB::unprepared("CREATE FUNCTION c3_synthetic_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic late failure'; END \$\$; CREATE TRIGGER c3_failure BEFORE INSERT ON recipe_batch_equipment FOR EACH ROW EXECUTE FUNCTION c3_synthetic_failure()");
        $hash = $this->snapshot();
        try {
            $this->editor->convertLegacy($this->actor, $id, $this->revision($id), $p['token'], true, true);
            $this->fail('Потрібен rollback');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic late failure', $e->getMessage());
            $this->assertSame($hash, $this->snapshot());
        }
    }

    public function test_gram_source_and_replayed_command_and_removed_uom_are_safe(): void
    {
        $b = $this->legacy();
        $id = $b['version'];
        DB::table('recipe_ingredients')->where('id', $b['row'])->update(['quantity' => '150.00', 'uom_id' => $b['g']]);
        $revision = $this->revision($id);
        $p = $this->editor->previewLegacy($this->actor, $id, $revision);
        try {
            DB::transaction(fn () => DB::table('uoms')->where('id', $b['g'])->delete());
            $this->fail('FK має захищати використовувану UOM');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', $e->getMessage());
        }
        $this->editor->convertLegacy($this->actor, $id, $revision, $p['token'], true, true);
        $hash = $this->snapshot();
        $this->denied(fn () => $this->editor->convertLegacy($this->actor, $id, $revision, $p['token'], true, true), 'ревізій');
        $this->assertSame($hash, $this->snapshot());
        $proof = json_decode(DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'week')->value('input_snapshot'), true)['legacy_source'];
        $state = $this->editor->open($this->actor, $id);
        $state['payload']['rows'][0]['quantity'] = '';
        $p = $this->editor->preview($this->actor, $id, $state['revision'], $state['payload']);
        $this->editor->save($this->actor, $id, $state['revision'], $state['payload'], $p['token']);
        $state = $this->editor->open($this->actor, $id);
        $state['payload']['rows'][0]['quantity'] = '150.00';
        $p = $this->editor->preview($this->actor, $id, $state['revision'], $state['payload']);
        $this->editor->save($this->actor, $id, $state['revision'], $state['payload'], $p['token']);
        $restored = DB::table('recipe_ingredients')->where('recipe_version_id', $id)->value('id');
        $this->assertSame($proof, json_decode(DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $restored)->where('format_code', 'week')->value('input_snapshot'), true)['legacy_source']);
    }

    public function test_component_author_order_survives_conversion_reload_and_save(): void
    {
        $b = $this->legacy();
        DB::table('recipe_components')->insert(['recipe_version_id' => $b['version'], 'title' => 'Спочатку', 'sort_order' => 2]);
        $p = $this->editor->previewLegacy($this->actor, $b['version'], $this->revision($b['version']));
        $id = $this->editor->convertLegacy($this->actor, $b['version'], $this->revision($b['version']), $p['token'], true, true);
        $state = $this->editor->open($this->actor, $id);
        $this->assertSame(['Спочатку', 'Основа'], array_column($state['payload']['components'], 'title'));
        $p = $this->editor->preview($this->actor, $id, $state['revision'], $state['payload']);
        $this->editor->save($this->actor, $id, $state['revision'], $state['payload'], $p['token']);
        $this->assertSame(['Спочатку', 'Основа'], DB::table('recipe_components')->where('recipe_version_id', $id)->orderBy('sort_order')->pluck('title')->all());
        $this->assertSame((string) $b['component'], $this->editor->open($this->actor, $id)['payload']['rows'][0]['component_id']);
    }

    public function test_livewire_open_preview_cancel_and_server_side_acceptance(): void
    {
        $this->installAuthSchema();
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $b = $this->legacy();
        $hash = $this->snapshot();
        $page = Livewire::withQueryParams(['version' => $b['version']])->test(RecipeBatches::class)
            ->assertSet('isLegacy', true)->assertSet('legacyPreview', [])
            ->call('previewLegacy')->assertHasNoErrors()->assertSee('1050.00')
            ->call('convertLegacy')->assertHasErrors(['batch'])
            ->call('cancelLegacy')->assertSet('legacyPreview', []);
        $this->assertSame($hash, $this->snapshot());
        $page->call('previewLegacy')->set('legacySourcesAccepted', true)->set('legacyReviewed', true)
            ->call('convertLegacy')->assertHasNoErrors()->assertRedirect(RecipeBatches::getUrl(['version' => $b['version']]));
    }

    public function test_sql_forbids_arbitrary_contract_switches_and_incomplete_transition(): void
    {
        $b = $this->legacy();
        foreach ([['editor_contract' => 'batch_v1', 'servings_count' => 1, 'lock_version' => DB::raw('lock_version+1')], ['status' => 'published', 'editor_contract' => 'batch_v1']] as $values) {
            try {
                DB::transaction(fn () => DB::table('recipe_versions')->where('id', $b['version'])->update($values));
                $this->fail('Guard має відхилити');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/Legacy conversion|Explicit publication review/', $e->getMessage());
            }
        }
        $p = $this->editor->previewLegacy($this->actor, $b['version'], $this->revision($b['version']));
        $id = $this->editor->convertLegacy($this->actor, $b['version'], $this->revision($b['version']), $p['token'], true, true);
        try {
            DB::transaction(fn () => DB::table('recipe_versions')->where('id', $id)->update(['editor_contract' => 'legacy_v1']));
            $this->fail('Зворотна конвертація');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Legacy conversion', $e->getMessage());
        }
        $published = $this->legacy(true);
        $hash = $this->snapshot();
        try {
            DB::transaction(fn () => DB::table('recipe_versions')->where('id', $published['version'])->update(['editor_contract' => 'batch_v1', 'servings_count' => 1, 'lock_version' => DB::raw('lock_version+1')]));
            $this->fail('Published legacy immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertSame($hash, $this->snapshot());
    }
}
