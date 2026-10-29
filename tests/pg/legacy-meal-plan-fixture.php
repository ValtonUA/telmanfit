<?php

use App\Models\MealPlan;
use Illuminate\Support\Facades\DB;

/** Synthetic legacy-only empty draft for the pre-existing structure regressions. */
function syntheticLegacyEmptyMealPlan(string $title): MealPlan
{
    $id = DB::table('meal_plans')->insertGetId(['title' => $title]);
    DB::table('meal_plan_versions')->insert(['meal_plan_id' => $id]);

    return MealPlan::findOrFail($id);
}
