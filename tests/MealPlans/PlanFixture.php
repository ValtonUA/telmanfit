<?php

namespace Tests\MealPlans;

use App\Models\MealPlan;
use Illuminate\Support\Facades\DB;

/** Синтетичний populated legacy plan; лише ізольовані тести. */
trait PlanFixture
{
    /** Старі порожні чернетки створюються лише як synthetic fixture для перевірки UX-3/legacy. */
    private function legacyEmptyPlan(string $title): MealPlan
    {
        $id = DB::table('meal_plans')->insertGetId(['title' => $title]);
        DB::table('meal_plan_versions')->insert(['meal_plan_id' => $id]);

        return MealPlan::findOrFail($id);
    }

    private function installMealItemSchema(): void
    {
        foreach (glob(database_path('migrations/2026_10_24_0[123]*.php')) as $file) {
            (require $file)->up();
        }
        (require database_path('migrations/2026_10_25_010000_add_meal_item_recipe_selectors.php'))->up();
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
    }

    private function populatedPlan(int $actor): array
    {
        $client = DB::table('clients')->insertGetId(['coach_user_id' => $actor]);
        $g = DB::table('uoms')->where('code', 'g')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'g', 'kind' => 'mass', 'label' => 'г']);
        $type = DB::table('meal_types')->insertGetId(['code' => 'synthetic-plan-'.bin2hex(random_bytes(4)), 'title' => 'Синтетичний прийом']);
        $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
        $ingredient = DB::table('ingredients')->insertGetId(['food_id' => $food, 'name' => 'Синтетичний інгредієнт', 'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '1.00', 'carbs_per_100g' => '2.00']);
        $serving = DB::table('uoms')->where('code', 'serving')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
        $recipe = DB::table('recipes')->insertGetId(['title' => 'Синтетичний legacy рецепт']);
        $recipeVersion = DB::table('recipe_versions')->insertGetId(['recipe_id' => $recipe, 'title' => 'Синтетичний legacy рецепт']);
        DB::table('recipe_ingredients')->insert(['recipe_version_id' => $recipeVersion, 'ingredient_id' => $ingredient, 'quantity' => '100.00', 'uom_id' => $g, 'quantity_grams' => '100.00']);
        $recipeFood = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
        DB::table('recipe_versions')->where('id', $recipeVersion)->update(['status' => 'published', 'food_id' => $recipeFood, 'version_number' => 1, 'published_at' => now(), 'kcal_per_serving' => '100.00', 'protein_per_serving' => '10.00', 'fat_per_serving' => '1.00', 'carbs_per_serving' => '2.00']);
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетичний історичний раціон', 'owner_client_id' => $client]);
        $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        for ($n = 1; $n <= 7; $n++) {
            $day = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $n]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $day, 'meal_type_id' => $type]);
            $item = DB::table('meal_items')->insertGetId(['meal_id' => $meal, 'food_id' => $food, 'quantity' => '100.10', 'uom_id' => $g, 'resolved_quantity_grams' => '100.10']);
            DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $recipeFood, 'quantity' => '0.50', 'uom_id' => $serving, 'sort_order' => 1]);
        }
        DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        $assignment = DB::table('client_meal_plan_assignments')->insertGetId(['client_id' => $client, 'meal_plan_version_id' => $version, 'created_by' => $actor, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return compact('client', 'g', 'type', 'food', 'ingredient', 'plan', 'version', 'day', 'meal', 'item', 'assignment');
    }
}
