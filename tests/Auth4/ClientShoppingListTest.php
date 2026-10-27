<?php

namespace Tests\Auth4;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Recipes\BatchFixture;
use Tests\Recipes\InheritedUomCases;
use Tests\Recipes\LegacyFixture;
use Tests\Unit\BatchEvaluatorTest;

/** Справжні ClientSession/HTTP/PG; успадковані gates перевіряють також усі tables/xmin. */
class ClientShoppingListTest extends ClientMealPlanTest
{
    use BatchFixture, LegacyFixture;

    protected string $endpoint = '/api/meal-plan/shopping-list';

    protected function emptyResponse(): array
    {
        return ['assignment' => null, 'weeks' => [], 'week' => null, 'days' => [], 'state' => 'no_assignment', 'items' => []];
    }

    private function shoppingVersion(array $selections, bool $tiny = false, bool $differentConversion = false, bool $can = false): array
    {
        $batch = $this->batch();
        if ($tiny) {
            DB::table('recipe_ingredients')->where('id', $batch['row'])->update(['quantity' => '0.01', 'quantity_grams' => '0.01']);
            $source = BatchEvaluatorTest::row();
            $source['quantity'] = '0.01';
            $this->storeEvaluated($batch, $source);
        }
        if ($can) {
            $canUom = DB::table('uoms')->insertGetId(['code' => 'shopping-can', 'kind' => 'count', 'label' => 'банка']);
            DB::table('ingredient_uoms')->insert(['ingredient_id' => $batch['ingredient'], 'uom_id' => $canUom, 'grams_per_uom' => '420.00']);
            $this->storeEvaluated($batch, InheritedUomCases::row('shopping-can', '1.00', '420.00', '0.50'));
            DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_ingredient_id=? AND mode='manual'", [$batch['row']]);
        }
        $this->publish($batch['version']);
        $legacy = $this->legacy(true);
        if ($differentConversion) {
            DB::table('ingredient_uoms')->where('ingredient_id', $legacy['ingredient'])->update(['grams_per_uom' => '40.00']);
        }
        $batchFood = DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id');
        $legacyFood = DB::table('recipe_versions')->where('id', $legacy['version'])->value('food_id');
        $ingredientFood = DB::table('ingredients')->where('id', $legacy['ingredient'])->value('food_id');
        $serving = DB::table('uoms')->where('code', 'serving')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетичні покупки', 'week_start_weekday' => now()->isoWeekday()]);
        $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $type = DB::table('meal_types')->insertGetId(['code' => 'shopping-'.bin2hex(random_bytes(4)), 'title' => 'Обід']);
        foreach ($selections as $n => $selection) {
            $day = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $n + 1]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $day, 'meal_type_id' => $type]);
            foreach ($selection as [$kind, $quantity, $divisor]) {
                $row = ['meal_id' => $meal, 'quantity' => $quantity, 'uom_id' => $serving];
                if (in_array($kind, ['single', 'week', 'half_week'], true)) {
                    $row += ['food_id' => $batchFood, 'recipe_version_id' => $batch['version'],
                        'recipe_batch_format_id' => $batch['formats'][$kind], 'recipe_portion_divisor' => $divisor];
                } elseif ($kind === 'legacy') {
                    $row += ['food_id' => $legacyFood];
                } else {
                    $row['food_id'] = $ingredientFood;
                    $row['uom_id'] = $kind === 'g' ? $legacy['g'] : $legacy['uom'];
                    $row['resolved_quantity_grams'] = $kind === 'g' ? $quantity : ($differentConversion ? '80.00' : '60.00');
                }
                DB::table('meal_items')->insert($row);
            }
        }
        DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $assignment = $this->assignment($version);

        return compact('batch', 'legacy', 'version', 'assignment');
    }

    public function test_seven_b_sevenths_are_one_frozen_b_and_get_has_no_writes(): void
    {
        $f = $this->shoppingVersion(array_fill(0, 7, [['week', '1.00', 7]]));
        $this->read($f['assignment']);
        $this->getJson($this->endpoint.'?week=1')->assertOk()->assertJsonPath('state', 'ready')
            ->assertJsonCount(7, 'days')->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.ingredient_id', (string) $f['batch']['ingredient'])
            ->assertJsonPath('items.0.quantity_grams', '1050.000000')
            ->assertJsonPath('items.0.quantity_grams_exact', '1050');
    }

    public function test_three_c_thirds_and_four_c_quarters_are_two_c_without_per_item_rounding(): void
    {
        $this->shoppingVersion([...array_fill(0, 3, [['half_week', '1.00', 3]]), ...array_fill(0, 4, [['half_week', '1.00', 4]])], tiny: true);
        $this->getJson($this->endpoint)->assertOk()->assertJsonPath('items.0.quantity_grams', '0.080000')
            ->assertJsonPath('items.0.quantity_grams_exact', '2/25');
    }

    public function test_fractional_a_b_c_consumption_has_exact_repeating_remainder(): void
    {
        $this->shoppingVersion(array_fill(0, 7, [['single', '0.50', 1], ['week', '0.25', 7], ['half_week', '0.50', 3]]), tiny: true);
        // 7 × (0.01/2 + 0.07/28 + 0.04/6) = 119/1200, жодного float.
        $this->getJson($this->endpoint)->assertOk()->assertJsonPath('items.0.quantity_grams', '0.099167')
            ->assertJsonPath('items.0.quantity_grams_exact', '119/1200');
    }

    public function test_legacy_whole_composition_is_divided_by_servings_and_mixed_units_are_preserved(): void
    {
        $f = $this->shoppingVersion(array_fill(0, 7, [['legacy', '0.50', null], ['g', '10.00', null], ['piece', '2.00', null]]));
        $r = $this->getJson($this->endpoint)->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.ingredient_id', (string) $f['legacy']['ingredient'])
            ->assertJsonPath('items.0.quantity_grams', '621.250000');
        $sources = collect($r->json('items.0.source_quantities'))->keyBy('uom_id');
        $this->assertSame('70.000000', $sources[$f['legacy']['g']]['quantity']);
        $this->assertSame('18.375000', $sources[$f['legacy']['uom']]['quantity']);
        $this->assertSame('30', $sources[$f['legacy']['uom']]['grams_per_unit_exact']);
        // Зміна live конверсії не переписує historical grams/ratio.
        DB::table('ingredient_uoms')->where('ingredient_id', $f['legacy']['ingredient'])->update(['grams_per_uom' => '99.00']);
        $this->getJson($this->endpoint)->assertExactJson($r->json());
    }

    public function test_incompatible_historical_conversions_for_same_unit_stay_separate(): void
    {
        $this->shoppingVersion(array_fill(0, 7, [['piece', '2.00', null], ['legacy', '0.50', null]]), differentConversion: true);
        $r = $this->getJson($this->endpoint)->assertOk()->assertJsonPath('items.0.quantity_grams', '691.250000')
            ->assertJsonCount(2, 'items.0.source_quantities');
        $sources = collect($r->json('items.0.source_quantities'))->keyBy('grams_per_unit_exact');
        $this->assertSame('4.375000', $sources['30']['quantity']);
        $this->assertSame('14.000000', $sources['40']['quantity']);
    }

    public function test_foreign_recipe_never_returns_a_partial_list_or_foreign_name(): void
    {
        $f = $this->shoppingVersion(array_fill(0, 7, [['week', '1.00', 7], ['legacy', '1.00', null]]));
        $other = DB::table('clients')->insertGetId(['coach_user_id' => $this->coach]);
        DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['owner_client_id' => $other]);
        $this->read(null, 409);
        $this->getJson($this->endpoint)->assertJsonPath('code', 'shopping_list_unavailable')->assertJsonMissingPath('items');
    }

    public function test_invalid_selector_is_not_silently_omitted(): void
    {
        $f = $this->shoppingVersion(array_fill(0, 7, [['piece', '2.00', null], ['week', '1.00', 7]]));
        // Довідник може змінитися після публікації; resolver має відмовити для цієї UOM.
        DB::table('ingredient_uoms')->where('ingredient_id', $f['legacy']['ingredient'])->delete();
        $this->read(null, 409);
        $this->getJson($this->endpoint)->assertJsonPath('code', 'shopping_list_unavailable')->assertJsonMissingPath('items');
    }

    public function test_postgresql_rejects_invalid_batch_selector_without_changing_the_list(): void
    {
        $f = $this->shoppingVersion(array_fill(0, 7, [['week', '1.00', 7]]));
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => DB::table('meal_items')->where('recipe_version_id', $f['batch']['version'])->update(['recipe_portion_divisor' => 3]));
            $this->fail('Невалідний divisor B має бути відхилено PostgreSQL.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshot());
        }
        $this->read($f['assignment']);
    }

    public function test_frozen_can_quantities_are_preserved_without_buying_a_can_per_serving(): void
    {
        $f = $this->shoppingVersion(array_fill(0, 7, [['week', '0.50', 7]]), can: true);
        $r = $this->getJson($this->endpoint)->assertOk()->assertJsonPath('items.0.quantity_grams', '210.000000')
            ->assertJsonPath('items.0.source_quantities.0.quantity', '0.500000')
            ->assertJsonPath('items.0.source_quantities.0.unit_label', 'банка')
            ->assertJsonPath('items.0.source_quantities.0.conversion_basis', 'frozen_conversion')
            ->assertJsonPath('items.0.source_quantities.0.grams_per_unit_exact', '420');
        DB::table('ingredient_uoms')->where('ingredient_id', $f['batch']['ingredient'])->update(['grams_per_uom' => '999.00']);
        DB::table('uoms')->where('code', 'shopping-can')->update(['label' => 'Змінений довідник']);
        $this->getJson($this->endpoint)->assertExactJson($r->json());
    }

    public function test_only_selected_week_and_inclusive_assignment_days_contribute(): void
    {
        $f = $this->shoppingVersion([...array_fill(0, 7, [['week', '1.00', 7]]), ...array_fill(0, 7, [['single', '0.50', 1]])]);
        $this->getJson($this->endpoint.'?week=1')->assertOk()->assertJsonPath('items.0.quantity_grams', '1050.000000');
        $this->getJson($this->endpoint.'?week=2')->assertOk()->assertJsonPath('items.0.quantity_grams', '525.000000');
        DB::table('client_meal_plan_assignments')->where('id', $f['assignment'])->update(['end_date' => now()->addDays(1)->toDateString()]);
        $this->getJson($this->endpoint.'?week=1')->assertOk()->assertJsonCount(2, 'days')->assertJsonPath('items.0.quantity_grams', '300.000000');
        $this->getJson($this->endpoint.'?week=2')->assertOk()->assertJsonPath('state', 'week_unavailable')->assertJsonCount(0, 'items');
    }

    public function test_newer_recipe_publication_and_same_names_never_replace_exact_ingredient_ids(): void
    {
        $f = $this->shoppingVersion(array_fill(0, 7, [['legacy', '0.50', null], ['single', '1.00', 1]]));
        $new = DB::table('recipe_versions')->insertGetId(['recipe_id' => $f['legacy']['recipe'], 'title' => 'Нова legacy версія', 'servings_count' => '4.00']);
        DB::table('recipe_ingredients')->insert(['recipe_version_id' => $new, 'ingredient_id' => $f['legacy']['ingredient'],
            'uom_id' => $f['legacy']['uom'], 'quantity' => '10.00', 'quantity_grams' => '300.00']);
        $food = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
        DB::table('recipe_versions')->where('id', $new)->update(['status' => 'published', 'version_number' => 2, 'food_id' => $food,
            'published_at' => now(), 'kcal_per_serving' => '300.00', 'protein_per_serving' => '30.00', 'fat_per_serving' => '6.00', 'carbs_per_serving' => '15.00']);
        DB::table('recipe_versions')->insert(['recipe_id' => $f['legacy']['recipe'], 'title' => 'Третя чернетка']);
        DB::table('ingredients')->whereIn('id', [$f['legacy']['ingredient'], $f['batch']['ingredient']])->update(['name' => 'Однакова назва']);
        $r = $this->getJson($this->endpoint.'?recipe_version_id='.$new)->assertOk()->assertJsonCount(2, 'items');
        $items = collect($r->json('items'))->keyBy('ingredient_id');
        $this->assertSame('131.250000', $items[$f['legacy']['ingredient']]['quantity_grams']);
        $this->assertSame('1050.000000', $items[$f['batch']['ingredient']]['quantity_grams']);
    }

    public function test_week_validation_and_unavailable_week_do_not_repeat_the_menu(): void
    {
        $this->shoppingVersion(array_fill(0, 7, [['week', '1.00', 7]]));
        foreach (['0', '-1', 'x', '1.5', '2147483648', '1&week[]=2'] as $week) {
            $this->read(null, 422, '?week='.$week);
        }
        $this->getJson($this->endpoint.'?week=2')->assertOk()->assertJsonPath('state', 'week_unavailable')
            ->assertJsonCount(0, 'days')->assertJsonCount(0, 'items');
    }
}
