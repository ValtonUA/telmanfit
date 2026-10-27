<?php

namespace Tests\MealPlans;

use Illuminate\Support\Facades\DB;
use Tests\Recipes\BatchFixture;
use Tests\Recipes\LegacyFixture;

/** Повний published synthetic aggregate для HTTP та Chromium, без seed у продукті. */
trait ClientMenuFixture
{
    use BatchFixture, LegacyFixture;

    public function menuVersion(int $days = 14, int $weekday = 1, ?int $owner = null): array
    {
        $batch = $this->batch();
        $this->publish($batch['version']);
        $legacy = $this->legacy(true);
        $legacyFood = DB::table('recipe_versions')->where('id', $legacy['version'])->value('food_id');
        $batchFood = DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id');
        $ingredientFood = DB::table('ingredients')->where('id', $legacy['ingredient'])->value('food_id');
        $serving = DB::table('uoms')->where('code', 'serving')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
        $types = [];
        foreach (['Сніданок', 'Обід'] as $title) {
            $types[] = DB::table('meal_types')->insertGetId(['code' => 't52-'.bin2hex(random_bytes(4)), 'title' => $title]);
        }
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетичний раціон — тест T5.2', 'week_start_weekday' => $weekday, 'owner_client_id' => $owner]);
        $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        for ($n = $days; $n >= 1; $n--) {
            $day = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $n]);
            foreach ([1, 0] as $sort) {
                $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $day, 'meal_type_id' => $types[$sort], 'sort_order' => $sort]);
                $rows = [
                    ['food_id' => $ingredientFood, 'quantity' => '125.50', 'uom_id' => $legacy['g'], 'resolved_quantity_grams' => '125.50'],
                    ['food_id' => $legacyFood, 'quantity' => '0.50', 'uom_id' => $serving],
                ];
                foreach ([['single', 1], ['week', 7], ['half_week', 3], ['half_week', 4]] as [$code, $divisor]) {
                    $rows[] = ['food_id' => $batchFood, 'quantity' => '1.25', 'uom_id' => $serving,
                        'recipe_version_id' => $batch['version'], 'recipe_batch_format_id' => $batch['formats'][$code], 'recipe_portion_divisor' => $divisor];
                }
                $rows[] = ['food_id' => $ingredientFood, 'quantity' => '2.00', 'uom_id' => $legacy['uom'], 'resolved_quantity_grams' => '60.00'];
                foreach (array_reverse($rows, true) as $order => $row) {
                    DB::table('meal_items')->insert($row + ['meal_id' => $meal, 'sort_order' => $order]);
                }
            }
        }
        DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return compact('plan', 'version', 'legacy', 'batch');
    }
}
