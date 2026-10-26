<?php

namespace Tests\MealPlans;

use Illuminate\Support\Facades\DB;

/** Синтетична published version для T4.5, проходить чинні publication guards. */
trait AssignmentFixture
{
    public function assignmentVersion(?int $owner = null, string $status = 'published', string $title = 'Синтетичний раціон T4.5'): int
    {
        return DB::transaction(function () use ($owner, $status, $title): int {
            $plan = DB::table('meal_plans')->insertGetId(['title' => $title, 'owner_client_id' => $owner]);
            $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
            if ($status === 'draft') {
                return $version;
            }
            $g = DB::table('uoms')->where('code', 'g')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'g', 'label' => 'г', 'kind' => 'mass']);
            $type = DB::table('meal_types')->insertGetId(['code' => 't45-'.bin2hex(random_bytes(4)), 'title' => 'Синтетичний прийом T4.5']);
            $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
            DB::table('ingredients')->insert(['food_id' => $food, 'name' => 'Синтетичний продукт T4.5', 'kcal_per_100g' => '100', 'protein_per_100g' => '10', 'fat_per_100g' => '1', 'carbs_per_100g' => '2']);
            for ($n = 1; $n <= 7; $n++) {
                $day = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $n]);
                $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $day, 'meal_type_id' => $type]);
                DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $food, 'quantity' => '100', 'uom_id' => $g, 'resolved_quantity_grams' => '100']);
            }
            DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $version;
        });
    }
}
