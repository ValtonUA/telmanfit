<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Транзакцією керує Laravel; знімок не містить BEGIN/COMMIT.
        DB::unprepared(file_get_contents(database_path('sql/stage1/01_nutrition_schema.sql')));
    }

    public function down(): void
    {
        // Явний зворотний порядок без CASCADE: сторонні залежності зупинять відкат.
        DB::unprepared(<<<'SQL'
            DROP TABLE client_meal_plan_assignments;
            DROP TABLE meal_items;
            DROP TABLE meals;
            DROP TABLE meal_types;
            DROP TABLE meal_plan_days;
            DROP TABLE meal_plan_versions;
            DROP TABLE meal_plans;
            DROP TABLE recipe_kitchen_equipment;
            DROP TABLE kitchen_equipment;
            DROP TABLE kitchen_equipment_categories;
            DROP TABLE recipe_directions;
            DROP TABLE recipe_ingredients;
            DROP TABLE recipe_components;
            DROP TABLE recipe_versions;
            DROP TABLE recipes;
            DROP TABLE ingredient_uoms;
            DROP TABLE ingredients;
            DROP TABLE foods;
            DROP TABLE uoms;
            DROP TABLE recipe_categories;
            DROP TABLE ingredient_categories;
            DROP FUNCTION protect_uom_code();
            DROP FUNCTION validate_plan_publication();
            DROP FUNCTION validate_recipe_publication();
            DROP FUNCTION assert_ingredient_grams(integer, smallint, numeric, numeric);
            DROP FUNCTION prevent_food_child_detach();
            DROP FUNCTION check_food_has_child();
            DROP FUNCTION guard_assignment_published();
            DROP FUNCTION guard_recipe_ingredient_measure();
            DROP FUNCTION guard_meal_item_measure();
            DROP FUNCTION validate_food_measure(integer, smallint, numeric);
            DROP FUNCTION reject_direct_published_insert();
            DROP FUNCTION guard_plan_detail();
            DROP FUNCTION guard_recipe_detail();
            DROP FUNCTION guard_plan_version();
            DROP FUNCTION guard_recipe_version();
            DROP FUNCTION assert_plan_version_editable(integer);
            DROP FUNCTION assert_recipe_version_editable(integer);
            SQL);
    }
};
