<?php

namespace Tests\Recipes;

use Illuminate\Support\Facades\DB;

final class CookingBrowserFixture
{
    use BatchFixture;

    public function make(): array
    {
        $batch = $this->batch();
        $this->publish($batch['version']);
        $serving = DB::table('uoms')->where('code', 'serving')->value('id');
        if ($serving === null) {
            $serving = DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
        }
        $weekday = (int) now()->isoWeekday();
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетичний reusable cooking plan', 'week_start_weekday' => $weekday]);
        $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $type = DB::table('meal_types')->insertGetId(['code' => 'cook-browser-'.bin2hex(random_bytes(4)), 'title' => 'Синтетичний прийом']);
        for ($day = 1; $day <= 7; $day++) {
            $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $day]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $dayId, 'meal_type_id' => $type, 'title' => 'Синтетична страва']);
            if ($day === 1) {
                DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id'),
                    'quantity' => '1.00', 'uom_id' => $serving, 'recipe_version_id' => $batch['version'],
                    'recipe_batch_format_id' => $batch['formats']['single'], 'recipe_portion_divisor' => 1]);
            }
        }
        DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return ['version' => $version, 'recipe_version' => $batch['version'], 'format' => $batch['formats']['single']];
    }
}
