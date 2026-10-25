<?php

namespace Tests\Postgres;

use App\Domain\MealPlans\MealItemKind;
use App\Models\MealItem;
use App\Services\MealPlans\MealItemResolver;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

class MealItemSelectorsTest extends PostgresTestCase
{
    use BatchFixture, PlanFixture;

    private function install(): void
    {
        foreach (glob(database_path('migrations/2026_10_24_0[123]*.php')) as $file) {
            (require $file)->up();
        }
        (require database_path('migrations/2026_10_25_010000_add_meal_item_recipe_selectors.php'))->up();
    }

    private function valid(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function rejected(callable $action, string $message): void
    {
        $before = DB::table('meal_items')->orderBy('id')->get()->toJson();
        DB::statement('SAVEPOINT selector_negative');
        try {
            $action();
            $this->valid();
            $this->fail('SQL мав відхилити запис');
        } catch (QueryException $e) {
            DB::statement('ROLLBACK TO SAVEPOINT selector_negative');
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            DB::statement('RELEASE SAVEPOINT selector_negative');
        }
        $this->assertSame($before, DB::table('meal_items')->orderBy('id')->get()->toJson());
    }

    private function fixture(): array
    {
        DB::table('uoms')->insertOrIgnore(['code' => 'g', 'kind' => 'mass', 'label' => 'gram']);
        $actor = DB::table('users')->insertGetId(['name' => 'Синтетичний автор', 'role' => 'admin']);
        $f = $this->populatedPlan($actor);
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетична чернетка']);
        $v = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $d = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $v, 'day_number' => 1]);
        $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $d, 'meal_type_id' => $f['type']]);
        $legacy = DB::table('recipe_versions')->where('title', 'Синтетичний legacy рецепт')->sole();

        return $f + ['draftMeal' => $meal, 'legacy' => $legacy, 'serving' => DB::table('uoms')->where('code', 'serving')->value('id')];
    }

    private function item(array $f, array $b, string $code = 'single', int $divisor = 1): array
    {
        return ['meal_id' => $f['draftMeal'], 'food_id' => DB::table('recipe_versions')->where('id', $b['version'])->value('food_id'),
            'quantity' => '0.50', 'uom_id' => $f['serving'], 'resolved_quantity_grams' => null,
            'recipe_version_id' => $b['version'], 'recipe_batch_format_id' => $b['formats'][$code], 'recipe_portion_divisor' => $divisor];
    }

    public function test_populated_upgrade_preserves_all_history_hashes_and_legacy_nutrition(): void
    {
        $f = $this->fixture();
        $tables = ['foods', 'ingredients', 'recipes', 'recipe_versions', 'recipe_ingredients', 'meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments'];
        $snapshots = [];
        foreach ($tables as $table) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->get();
        }
        $this->assertSame(file_get_contents(base_path('docs/14_meal_item_selectors.sql')), file_get_contents(database_path('sql/meal_plans/00_selectors.sql')));
        $this->install();
        $constraints = DB::select("SELECT conname,pg_get_constraintdef(oid) definition FROM pg_constraint WHERE conrelid='meal_items'::regclass AND conname IN ('meal_items_recipe_food_fk','meal_items_recipe_format_fk')");
        $this->assertCount(2, $constraints);
        foreach ($constraints as $constraint) {
            $this->assertStringContainsString('ON UPDATE RESTRICT ON DELETE RESTRICT', $constraint->definition);
        }
        foreach ($snapshots as $table => $rows) {
            $columns = array_keys((array) $rows->first());
            $this->assertSame(hash('sha256', $rows->toJson()), hash('sha256', DB::table($table)->orderBy('id')->get($columns)->toJson()), $table);
        }
        $this->assertSame(0, DB::table('meal_items')->whereNotNull('recipe_version_id')->orWhereNotNull('recipe_batch_format_id')->orWhereNotNull('recipe_portion_divisor')->count());
        $resolver = app(MealItemResolver::class);
        $ingredient = $resolver->resolve(MealItem::findOrFail($f['item']));
        $this->assertSame(MealItemKind::Ingredient, $ingredient->kind);
        $this->assertSame('100.100000', $ingredient->nutrition['kcal']);
        $legacyId = DB::table('meal_items')->where('food_id', $f['legacy']->food_id)->value('id');
        $legacy = $resolver->resolve($legacyId);
        $this->assertSame(MealItemKind::LegacyRecipe, $legacy->kind);
        $this->assertSame($f['legacy']->id, $legacy->recipeVersionId);
        $this->assertNull($legacy->formatId);
        $this->assertSame('50.000000', $legacy->nutrition['kcal']);
        $this->rejected(fn () => DB::table('meal_items')->where('id', $f['item'])->update(['sort_order' => 9]), 'immutable');
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_25_010000_add_meal_item_recipe_selectors.php'))->down();
    }

    public function test_exact_published_formats_fractional_quantities_and_physical_composition(): void
    {
        $f = $this->fixture();
        $this->install();
        $b = $this->batch();
        $this->publish($b['version']);
        $this->valid();
        $physicalBefore = DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->orderBy('format_id')->get()->toJson();
        foreach ([['single', 1, MealItemKind::BatchA, '92.587500'], ['week', 7, MealItemKind::BatchB, '92.587500'],
            ['half_week', 3, MealItemKind::BatchC3, '108.018750'], ['half_week', 4, MealItemKind::BatchC4, '81.014063']] as [$code, $divisor, $kind, $kcal]) {
            $id = DB::table('meal_items')->insertGetId($this->item($f, $b, $code, $divisor));
            $out = app(MealItemResolver::class)->resolve($id);
            $this->assertSame($kind, $out->kind);
            $this->assertSame($b['version'], $out->recipeVersionId);
            $this->assertSame($b['formats'][$code], $out->formatId);
            $this->assertSame($divisor, $out->divisor);
            $this->assertSame('0.50', $out->quantity);
            $this->assertSame($kcal, $out->nutrition['kcal']);
        }
        $this->valid();
        $this->assertSame($physicalBefore, DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->orderBy('format_id')->get()->toJson());
        $this->assertSame(['1050.00', '525.00'], DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->orderByDesc('format_code')->pluck('quantity_grams')->all());
        // Новіші drafts не змінюють exact published food.
        $root = DB::table('recipe_versions')->find($b['version']);
        DB::table('recipe_versions')->insert(['recipe_id' => $root->recipe_id, 'title' => 'Наступна версія']);
        $this->assertSame($kcal, app(MealItemResolver::class)->resolve($id)->nutrition['kcal']);
    }

    public function test_direct_sql_null_matrix_foreign_identity_units_and_published_guards(): void
    {
        $f = $this->fixture();
        $this->install();
        $a = $this->batch();
        $b = $this->batch();
        $draft = $this->batch();
        $this->publish($a['version']);
        $this->publish($b['version']);
        $this->valid();
        $base = $this->item($f, $a);
        foreach (range(0, 6) as $mask) {
            $row = $base;
            foreach (['recipe_version_id', 'recipe_batch_format_id', 'recipe_portion_divisor'] as $n => $field) {
                if (! ($mask & (1 << $n))) {
                    $row[$field] = null;
                }
            }
            $this->rejected(fn () => DB::table('meal_items')->insert($row), $mask === 0 ? 'explicit selectors' : 'complete');
        }
        foreach ([
            [['food_id' => $f['food']], 'Ingredient requires NULL'],
            [['food_id' => $f['legacy']->food_id], 'exact published'],
            [['recipe_version_id' => $b['version']], 'exact published'],
            [['recipe_version_id' => $draft['version']], 'exact published'],
            [['recipe_version_id' => 2147483647], 'exact published'],
            [['recipe_batch_format_id' => $b['formats']['single']], 'does not belong'],
            [['recipe_batch_format_id' => 2147483647], 'does not belong'],
            [['recipe_portion_divisor' => 7], 'divisor mismatch'],
            [['recipe_batch_format_id' => $a['formats']['week'], 'recipe_portion_divisor' => 1], 'divisor mismatch'],
            [['recipe_batch_format_id' => $a['formats']['half_week'], 'recipe_portion_divisor' => 7], 'divisor mismatch'],
            [['uom_id' => $f['g']], 'serving unit'], [['resolved_quantity_grams' => '1.00'], 'NULL resolved grams'],
        ] as [$changes, $message]) {
            $this->rejected(fn () => DB::table('meal_items')->insert(array_replace($base, $changes)), $message);
        }
        // Усі partial/full non-NULL комбінації заборонені й для ingredient/legacy.
        foreach (['ingredient', 'legacy'] as $type) {
            foreach (range(1, 7) as $mask) {
                $row = array_replace($base, ['food_id' => $type === 'ingredient' ? $f['food'] : $f['legacy']->food_id,
                    'uom_id' => $type === 'ingredient' ? $f['g'] : $f['serving'],
                    'resolved_quantity_grams' => $type === 'ingredient' ? '0.50' : null,
                    'recipe_version_id' => $f['legacy']->id]);
                foreach (['recipe_version_id', 'recipe_batch_format_id', 'recipe_portion_divisor'] as $n => $field) {
                    if (! ($mask & (1 << $n))) {
                        $row[$field] = null;
                    }
                }
                $message = $mask === 7 ? ($type === 'ingredient' ? 'Ingredient requires NULL' : 'Legacy recipe requires NULL') : 'complete';
                $this->rejected(fn () => DB::table('meal_items')->insert($row), $message);
            }
        }
        $id = DB::table('meal_items')->insertGetId($base);
        $this->rejected(fn () => DB::table('meal_items')->where('id', $id)->update(['recipe_portion_divisor' => null]), 'complete');
        $this->rejected(fn () => DB::table('meal_items')->where('id', $id)->update(['recipe_version_id' => null, 'recipe_batch_format_id' => null, 'recipe_portion_divisor' => null]), 'explicit selectors');
        $ingredient = ['meal_id' => $f['draftMeal'], 'food_id' => $f['food'], 'quantity' => '0.50', 'uom_id' => $f['g'], 'resolved_quantity_grams' => '0.50'];
        DB::table('meal_items')->insert($ingredient);
        $this->rejected(fn () => DB::table('meal_items')->insert(array_replace($ingredient, ['resolved_quantity_grams' => null])), 'resolved grams');
        $this->rejected(fn () => DB::table('meal_items')->insert(array_replace($ingredient, ['uom_id' => $f['serving']])), 'not permitted');
        $this->rejected(fn () => DB::table('meal_items')->insert(array_replace($base, ['food_id' => $f['food'], 'uom_id' => $f['g'], 'resolved_quantity_grams' => '0.50'])), 'Ingredient requires NULL');
        $this->rejected(fn () => DB::table('meal_items')->insert(array_replace($base, ['food_id' => $f['legacy']->food_id, 'recipe_version_id' => $f['legacy']->id])), 'Legacy recipe requires NULL');
        foreach (['recipe_batch_formats', 'recipe_batch_quantities', 'recipe_ingredients'] as $table) {
            $this->rejected(fn () => DB::table($table)->where('recipe_version_id', $a['version'])->delete(), 'immutable');
            $this->rejected(fn () => DB::table($table)->where('recipe_version_id', $a['version'])->update(['recipe_version_id' => $b['version']]), 'immutable');
        }
        $this->rejected(fn () => DB::table('recipe_versions')->where('id', $a['version'])->update(['status' => 'draft', 'lock_version' => DB::raw('lock_version+1')]), 'immutable');
    }

    public function test_deferred_failure_rolls_back_selectors_and_reader_rejects_missing_item(): void
    {
        $f = $this->fixture();
        $this->install();
        $b = $this->batch();
        $this->publish($b['version']);
        $this->valid();
        DB::unprepared("CREATE FUNCTION selector_late_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic selector late fault'; END $$;
            CREATE CONSTRAINT TRIGGER selector_late_fault AFTER INSERT ON meal_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION selector_late_fault();");
        $this->rejected(fn () => DB::table('meal_items')->insert($this->item($f, $b)), 'synthetic selector late fault');
        $this->expectException(DomainException::class);
        app(MealItemResolver::class)->resolve(2147483647);
    }

    public function test_pre_upgrade_batch_without_selectors_has_explicit_reader_failure(): void
    {
        $f = $this->fixture();
        // Непорожній pre-upgrade batch food теж не отримує вигаданий A/1 backfill.
        foreach (glob(database_path('migrations/2026_10_24_0[123]*.php')) as $file) {
            (require $file)->up();
        }
        $b = $this->batch();
        $this->publish($b['version']);
        $id = DB::table('meal_items')->insertGetId(['meal_id' => $f['draftMeal'], 'food_id' => DB::table('recipe_versions')->where('id', $b['version'])->value('food_id'), 'quantity' => '0.50', 'uom_id' => $f['serving']]);
        $this->valid();
        (require database_path('migrations/2026_10_25_010000_add_meal_item_recipe_selectors.php'))->up();
        $this->assertNull(DB::table('meal_items')->find($id)->recipe_batch_format_id);
        $this->expectException(DomainException::class);
        app(MealItemResolver::class)->resolve($id);
    }
}
