<?php

namespace Tests\Postgres;

use App\Filament\Resources\Catalog\Pages\CreateIngredient;
use App\Filament\Resources\Catalog\Pages\EditIngredient;
use App\Filament\Resources\Catalog\Pages\ListIngredient;
use App\Models\Catalog\Ingredient;
use App\Models\Catalog\IngredientCategory;
use App\Models\Catalog\KitchenEquipment;
use App\Models\Catalog\KitchenEquipmentCategory;
use App\Models\Catalog\RecipeCategory;
use App\Models\Catalog\Uom;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Recipes\BatchEditor;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Recipes\BatchFixture;

class FoodCatalogTest extends PostgresTestCase
{
    use BatchFixture;

    private CatalogWriter $writer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        foreach (glob(database_path('migrations/2026_10_24_0[123]0000_*.php')) as $file) {
            (require $file)->up();
        }
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
        (require database_path('migrations/2026_10_26_000100_add_kitchen_equipment_image_url.php'))->up();
        $this->writer = app(CatalogWriter::class);
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic catalog admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['recipes.batch_editor_enabled' => false]);
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => 'Синтетичний продукт', 'ingredient_category_id' => null, 'kcal_per_100g' => '100.00',
            'protein_per_100g' => '10.10', 'fat_per_100g' => '0.00', 'carbs_per_100g' => '5.00', 'conversions' => []], $overrides);
    }

    public function test_atomic_food_subtype_mass_assignment_and_implicit_gram(): void
    {
        $record = $this->writer->save($this->actor, new Ingredient, $this->input(['food_id' => 123456, 'food_type' => 'recipe_version', 'id' => 123456]));
        $this->assertSame('ingredient', $record->food_type);
        $this->assertNotSame(123456, $record->food_id);
        $this->assertNotSame(123456, $record->id);
        $this->assertSame('ingredient', DB::table('foods')->where('id', $record->food_id)->value('food_type'));
        $this->assertSame('10.10', $record->protein_per_100g);
        $this->assertSame(0, DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->count());
        $food = $record->food_id;
        $record = $this->writer->save($this->actor, $record, $this->input(['name' => 'Нова назва']), $this->writer->revision($record));
        $this->assertSame($food, $record->food_id);
        $this->assertFalse(config('recipes.batch_editor_enabled'));
    }

    public static function invalidDecimals(): array
    {
        return array_map(fn ($n) => [$n], ['NaN', 'Infinity', '-1', '100000', '0.001', '1,23', '1 000', '1e2', '', '01', '.5']);
    }

    #[DataProvider('invalidDecimals')]
    public function test_decimal_validation_rolls_back_without_orphan_food(string $number): void
    {
        $before = DB::table('foods')->count();
        try {
            $this->writer->save($this->actor, new Ingredient, $this->input(['kcal_per_100g' => $number]));
            $this->fail('Невалідне число');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('kcal_per_100g', $e->errors());
        }
        $this->assertSame($before, DB::table('foods')->count());
        $unit = $this->writer->save($this->actor, new Uom, ['code' => 'invalid-factor-test', 'kind' => 'count', 'label' => 'Синтетична одиниця']);
        try {
            $this->writer->save($this->actor, new Ingredient, $this->input(['conversions' => [['uom_id' => $unit->id, 'grams_per_uom' => $number]]]));
            $this->fail('Невалідний decimal factor');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('conversions.0.grams_per_uom', $e->errors());
        }
        $this->assertSame($before, DB::table('foods')->count());
    }

    public function test_late_write_failure_rolls_back_both_food_and_ingredient(): void
    {
        $before = [DB::table('foods')->count(), Ingredient::count()];
        DB::unprepared("CREATE FUNCTION catalog_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic late failure'; END $$; CREATE TRIGGER catalog_test_fail AFTER INSERT ON ingredients FOR EACH ROW EXECUTE FUNCTION catalog_test_fail();");
        try {
            $this->writer->save($this->actor, new Ingredient, $this->input());
            $this->fail('Пізня відмова');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic late failure', $e->getMessage());
        }
        $this->assertSame($before, [DB::table('foods')->count(), Ingredient::count()]);
    }

    public function test_conversion_default_transfer_unique_gram_and_no_deletion(): void
    {
        $g = $this->writer->save($this->actor, new Uom, ['code' => 'g', 'kind' => 'mass', 'label' => 'г']);
        $u = $this->writer->save($this->actor, new Uom, ['code' => 'catalog-test', 'kind' => 'count', 'label' => 'тестова одиниця']);
        $v = $this->writer->save($this->actor, new Uom, ['code' => 'catalog-test-2', 'kind' => 'count', 'label' => 'друга одиниця']);
        $rows = [['uom_id' => $u->id, 'grams_per_uom' => '10.00', 'is_default' => true], ['uom_id' => $v->id, 'grams_per_uom' => '20.00', 'is_default' => false]];
        $record = $this->writer->save($this->actor, new Ingredient, $this->input(['conversions' => $rows]));
        $rows[0]['is_default'] = false;
        $rows[1]['is_default'] = true;
        $record = $this->writer->save($this->actor, $record, $this->input(['conversions' => $rows]), $this->writer->revision($record));
        $this->assertSame($v->id, DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->where('is_default', true)->value('uom_id'));
        foreach ([[], [$rows[0]], array_merge($rows, [$rows[0]]), array_map(fn ($r) => array_replace($r, ['is_default' => true]), $rows), [['uom_id' => $g->id, 'grams_per_uom' => '1.00']], [['uom_id' => $u->id, 'grams_per_uom' => 'NaN']], [['uom_id' => $u->id, 'grams_per_uom' => '0.00']], [['uom_id' => 32767, 'grams_per_uom' => '1.00']]] as $bad) {
            try {
                $this->writer->save($this->actor, $record, $this->input(['conversions' => $bad]), $this->writer->revision($record));
                $this->fail('Невалідні conversions');
            } catch (ValidationException) {
                $this->assertSame(2, DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->count());
            }
        }
    }

    public function test_code_kind_unique_categories_and_delete_policy(): void
    {
        foreach ([new IngredientCategory, new RecipeCategory, new KitchenEquipmentCategory, new KitchenEquipment] as $model) {
            $record = $this->writer->save($this->actor, $model, ['name' => 'Synthetic unique catalog']);
            $this->assertFalse(Gate::allows('delete', $record));
            $this->assertFalse(Gate::allows('deleteAny', $model::class));
            try {
                $this->writer->save($this->actor, $model->newInstance(), ['name' => 'Synthetic unique catalog']);
                $this->fail('Unique');
            } catch (ValidationException) {
                $this->assertSame(1, $model->newQuery()->count());
            }
        }
        foreach (['g' => 'mass', 'serving' => 'serving'] as $code => $kind) {
            $unit = $this->writer->save($this->actor, new Uom, compact('code', 'kind') + ['label' => 'Одиниця']);
            foreach ([['code' => 'changed', 'kind' => $kind], ['code' => $code, 'kind' => 'other']] as $bad) {
                try {
                    $this->writer->save($this->actor, $unit, $bad + ['label' => 'Одиниця'], $this->writer->revision($unit));
                    $this->fail('Immutable');
                } catch (ValidationException) {
                    $this->assertSame($kind, $unit->fresh()->kind);
                }
            }
        }
    }

    public function test_stale_form_and_forged_foreign_key_fail_closed(): void
    {
        $record = $this->writer->save($this->actor, new Ingredient, $this->input());
        $old = $this->writer->revision($record);
        $record = $this->writer->save($this->actor, $record, $this->input(['name' => 'Збережено іншим автором']), $old);
        foreach ([$this->input(), $this->input(['ingredient_category_id' => 32767])] as $data) {
            try {
                $this->writer->save($this->actor, $record, $data, $data['ingredient_category_id'] ? $this->writer->revision($record) : $old);
                $this->fail('Stale/FK');
            } catch (ValidationException) {
                $this->assertSame('Збережено іншим автором', $record->fresh()->name);
            }
        }
    }

    public function test_livewire_create_edit_security_and_current_role(): void
    {
        $page = Livewire::test(CreateIngredient::class)->fillForm($this->input())->call('create')->assertHasNoFormErrors();
        $record = Ingredient::firstOrFail();
        $page->assertRedirect();
        Livewire::test(EditIngredient::class, ['record' => $record->id])->fillForm(['name' => 'Змінено у Filament'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Змінено у Filament', $record->fresh()->name);
        $mounted = Livewire::test(EditIngredient::class, ['record' => $record->id])->fillForm(['name' => 'Заборонено']);
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
        $mounted->call('save')->assertForbidden();
        foreach ([false, true] as $flag) {
            config(['recipes.batch_editor_enabled' => $flag]);
            foreach (['coach', 'client'] as $role) {
                DB::table('users')->where('id', $this->actor->id)->update(['role' => $role]);
                $this->actingAs($this->actor->fresh());
                Livewire::test(CreateIngredient::class)->assertForbidden();
                Livewire::test(EditIngredient::class, ['record' => $record->id])->assertForbidden();
                $this->get('/admin/catalog/ingredients')->assertForbidden();
                $this->assertFalse(Gate::allows('create', Ingredient::class));
            }
        }
        $this->assertSame('Змінено у Filament', $record->fresh()->name);
    }

    public function test_all_catalog_resources_admin_create_edit_and_flag_independence(): void
    {
        foreach ([false, true] as $flag) {
            config(['recipes.batch_editor_enabled' => $flag]);
            foreach (['IngredientCategory', 'RecipeCategory', 'KitchenEquipmentCategory', 'KitchenEquipment', 'Uom'] as $name) {
                $create = 'App\\Filament\\Resources\\Catalog\\Pages\\Create'.$name;
                $edit = 'App\\Filament\\Resources\\Catalog\\Pages\\Edit'.$name;
                $model = 'App\\Models\\Catalog\\'.$name;
                $input = $name === 'Uom' ? ['code' => 'synthetic-'.(int) $flag, 'label' => 'Тест', 'kind' => 'other'] : ['name' => 'Тест '.(int) $flag];
                Livewire::test($create)->fillForm($input)->call('create')->assertHasNoFormErrors();
                $record = $model::query()->orderByDesc('id')->firstOrFail();
                $field = $name === 'Uom' ? 'label' : 'name';
                Livewire::test($edit, ['record' => $record->id])->fillForm([$field => 'Змінено '.(int) $flag])->call('save')->assertHasNoFormErrors();
                $this->assertSame('Змінено '.(int) $flag, $record->fresh()->{$field});
                $this->assertFalse(Gate::allows('delete', $record));
            }
        }
    }

    public function test_pg_constraints_remain_active_for_subtype_uniqueness_and_restrict(): void
    {
        $category = $this->writer->save($this->actor, new IngredientCategory, ['name' => 'Synthetic referenced']);
        $unit = $this->writer->save($this->actor, new Uom, ['code' => 'synthetic-pg', 'label' => 'Тест', 'kind' => 'count']);
        $record = $this->writer->save($this->actor, new Ingredient, $this->input(['ingredient_category_id' => $category->id,
            'conversions' => [['uom_id' => $unit->id, 'grams_per_uom' => '10.00', 'is_default' => true]]]));
        $other = $this->writer->save($this->actor, new Uom, ['code' => 'synthetic-pg-2', 'label' => 'Тест 2', 'kind' => 'count']);
        foreach ([
            fn () => DB::table('foods')->insert(['food_type' => 'ingredient']),
            fn () => DB::table('ingredients')->where('id', $record->id)->update(['food_type' => 'recipe_version']),
            fn () => DB::table('ingredients')->where('id', $record->id)->update(['kcal_per_100g' => '-1']),
            fn () => DB::table('ingredients')->where('id', $record->id)->update(['kcal_per_100g' => '100000']),
            fn () => DB::table('ingredient_uoms')->insert(['ingredient_id' => $record->id, 'uom_id' => $unit->id, 'grams_per_uom' => '10.00']),
            fn () => DB::table('ingredient_uoms')->insert(['ingredient_id' => $record->id, 'uom_id' => $other->id, 'grams_per_uom' => '10.00', 'is_default' => true]),
            fn () => DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->update(['grams_per_uom' => 0]),
            fn () => DB::table('uoms')->where('id', $unit->id)->update(['code' => 'forged']),
            fn () => DB::table('uoms')->where('id', $unit->id)->delete(),
            fn () => DB::table('ingredient_categories')->where('id', $category->id)->delete(),
            fn () => DB::table('ingredients')->where('id', $record->id)->delete(),
        ] as $operation) {
            try {
                DB::transaction(function () use ($operation) {
                    $operation();
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('SQL invariant bypass');
            } catch (QueryException) {
                $this->assertSame($record->food_id, $record->fresh()->food_id);
                $this->assertSame('synthetic-pg', $unit->fresh()->code);
            }
        }
    }

    public function test_catalog_list_search_filters_and_empty_results(): void
    {
        $category = $this->writer->save($this->actor, new IngredientCategory, ['name' => 'Категорія пошуку']);
        $first = $this->writer->save($this->actor, new Ingredient, $this->input(['name' => 'Distinct catalog search', 'ingredient_category_id' => $category->id]));
        $second = $this->writer->save($this->actor, new Ingredient, $this->input(['name' => 'Інший продукт']));
        Livewire::test(ListIngredient::class)
            ->assertCanSeeTableRecords([$first, $second])->searchTable('Distinct')
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->searchTable('')->filterTable('ingredient_category_id', $category->id)
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->searchTable('No matching synthetic record')->assertCountTableRecords(0);
    }

    public function test_catalog_writer_preserves_published_manual_snapshots_foods_plans_and_assignments(): void
    {
        config(['recipes.batch_editor_enabled' => true]);
        $b = $this->batch();
        $editor = app(BatchEditor::class);
        $state = $editor->open($this->actor, $b['version']);
        $state['payload']['rows'][0]['week']['mode'] = 'manual';
        $state['payload']['rows'][0]['week']['quantity'] = '1000.00';
        $p = $editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $editor->save($this->actor, $b['version'], $state['revision'], $state['payload'], $p['token'], true, true);
        $this->publish($b['version']);
        $food = DB::table('recipe_versions')->where('id', $b['version'])->value('food_id');
        $serving = $this->writer->save($this->actor, new Uom, ['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Synthetic historical plan']);
        $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $type = DB::table('meal_types')->insertGetId(['code' => 'catalog-history', 'title' => 'Synthetic meal']);
        for ($day = 1; $day <= 7; $day++) {
            $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $day]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $dayId, 'meal_type_id' => $type]);
            DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $food, 'quantity' => '1.00', 'uom_id' => $serving->id]);
        }
        DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        $client = DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]);
        DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $version, 'start_date' => '2026-01-01']);
        $tables = ['foods', 'recipes', 'recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities',
            'recipe_kitchen_equipment', 'recipe_batch_equipment', 'meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderByRaw('to_jsonb('.$table.')::text')->get()->toJson(), $tables);
        $before = $snapshot();
        $unit = $this->writer->save($this->actor, new Uom, ['code' => 'catalog-history', 'kind' => 'count', 'label' => 'тест']);
        $record = Ingredient::findOrFail($b['ingredient']);
        $record = $this->writer->save($this->actor, $record, $this->input(['conversions' => [['uom_id' => $unit->id, 'grams_per_uom' => '25.00', 'is_default' => true]]]), $this->writer->revision($record));
        $this->assertSame($before, $snapshot());
        $record = $this->writer->save($this->actor, $record, $this->input(['name' => 'Synthetic changed nutrition', 'kcal_per_100g' => '222.22',
            'conversions' => [['uom_id' => $unit->id, 'grams_per_uom' => '30.00', 'is_default' => false]]]), $this->writer->revision($record));
        $this->writer->save($this->actor, $unit, ['code' => $unit->code, 'kind' => 'count', 'label' => 'нова назва'], $this->writer->revision($unit));
        $g = Uom::findOrFail($b['g']);
        $this->writer->save($this->actor, $g, ['code' => 'g', 'kind' => 'mass', 'label' => 'г'], $this->writer->revision($g));
        $this->assertSame($before, $snapshot());
        $this->assertSame('1000.00', DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->where('format_code', 'week')->value('quantity'));
    }

    public function test_catalog_updates_leave_frozen_history_and_require_refresh_before_publish(): void
    {
        config(['recipes.batch_editor_enabled' => true]);
        $b = $this->batch();
        $editor = app(BatchEditor::class);
        $state = $editor->open($this->actor, $b['version']);
        $p = $editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $editor->save($this->actor, $b['version'], $state['revision'], $state['payload'], $p['token'], true, true);
        $state = $editor->open($this->actor, $b['version']);
        $before = DB::table('recipe_ingredients')->where('recipe_version_id', $b['version'])->get()->toJson();
        $publicationPreview = $editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $record = Ingredient::findOrFail($b['ingredient']);
        $snapshot = $this->writer->snapshot($record);
        $record = $this->writer->save($this->actor, $record, $this->input(['kcal_per_100g' => '199.99', 'conversions' => array_map(fn ($r) => array_intersect_key($r, array_flip(['uom_id', 'grams_per_uom', 'is_default'])), $snapshot['conversions'])]), $this->writer->revision($record));
        $this->assertSame($before, DB::table('recipe_ingredients')->where('recipe_version_id', $b['version'])->get()->toJson());
        $p = $editor->preview($this->actor, $b['version'], $state['revision'], $state['payload']);
        $this->assertNotEmpty($p['diff']);
        try {
            $editor->publish($this->actor, $b['version'], $state['revision'], $publicationPreview['token']);
            $this->fail('Catalog changed between preview and Publish');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('застаріли', $e->getMessage());
            $this->assertSame('draft', DB::table('recipe_versions')->where('id', $b['version'])->value('status'));
        }
        try {
            $editor->save($this->actor, $b['version'], $state['revision'], $state['payload'], $p['token']);
            $this->fail('Потрібен Refresh');
        } catch (ValidationException) {
            $this->assertSame($before, DB::table('recipe_ingredients')->where('recipe_version_id', $b['version'])->get()->toJson());
        }
        $editor->save($this->actor, $b['version'], $state['revision'], $state['payload'], $p['token'], true, true);
        $this->assertNotSame($before, DB::table('recipe_ingredients')->where('recipe_version_id', $b['version'])->get()->toJson());
    }
}
