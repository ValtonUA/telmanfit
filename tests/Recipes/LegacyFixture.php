<?php

namespace Tests\Recipes;

use Illuminate\Support\Facades\DB;

/** Синтетична legacy-історія; жодних клієнтських даних або seed. */
trait LegacyFixture
{
    private function legacy(bool $published = false): array
    {
        $suffix = bin2hex(random_bytes(4));
        $g = DB::table('uoms')->where('code', 'g')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'g', 'label' => 'г', 'kind' => 'mass']);
        $uom = DB::table('uoms')->insertGetId(['code' => 'legacy-piece-'.bin2hex(random_bytes(4)), 'label' => 'шт.', 'kind' => 'count']);
        $category = DB::table('recipe_categories')->insertGetId(['name' => 'Legacy synthetic recipe '.$suffix]);
        $ingredientCategory = DB::table('ingredient_categories')->insertGetId(['name' => 'Legacy synthetic ingredient '.$suffix]);
        $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
        $ingredient = DB::table('ingredients')->insertGetId(['food_id' => $food, 'name' => 'Legacy synthetic ingredient '.$suffix, 'ingredient_category_id' => $ingredientCategory,
            'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '2.00', 'carbs_per_100g' => '5.00']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $ingredient, 'uom_id' => $uom, 'grams_per_uom' => '30.00']);
        $recipe = DB::table('recipes')->insertGetId(['title' => 'Legacy synthetic', 'recipe_category_id' => $category]);
        $version = DB::table('recipe_versions')->insertGetId(['recipe_id' => $recipe, 'title' => 'Legacy synthetic', 'servings_count' => 4]);
        $component = DB::table('recipe_components')->insertGetId(['recipe_version_id' => $version, 'title' => 'Основа', 'sort_order' => 7]);
        $row = DB::table('recipe_ingredients')->insertGetId(['recipe_version_id' => $version, 'ingredient_id' => $ingredient, 'quantity' => '5.00', 'quantity_grams' => '150.00', 'uom_id' => $uom, 'component_id' => $component, 'sort_order' => 9]);
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Legacy synthetic pan '.$suffix]);
        $equipment = DB::table('recipe_kitchen_equipment')->insertGetId(['recipe_version_id' => $version, 'kitchen_equipment_id' => $tool,
            'min_value' => '1.00', 'uom_id' => $uom, 'scaling_mode' => 'proportional', 'tip' => 'Перевірити місткість']);
        DB::table('recipe_directions')->insert(['recipe_version_id' => $version, 'direction_number' => 1,
            'direction_text' => 'Взяти {{ingredient:'.$row.':grams}}, {{equipment:'.$equipment.':quantity}}. 20 хв, 180°C.', 'image_url' => 'https://example.invalid/legacy.png']);
        if ($published) {
            $food = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
            DB::table('recipe_versions')->where('id', $version)->update(['food_id' => $food, 'status' => 'published', 'version_number' => 1, 'published_at' => now(),
                'kcal_per_serving' => '150.00', 'protein_per_serving' => '15.00', 'fat_per_serving' => '3.00', 'carbs_per_serving' => '7.50']);
        }

        return compact('version', 'recipe', 'row', 'ingredient', 'g', 'uom', 'component', 'equipment');
    }
}
