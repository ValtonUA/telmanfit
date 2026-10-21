<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Транзакцією керує Laravel; знімок не містить BEGIN/COMMIT.
        DB::unprepared(file_get_contents(database_path('sql/stage1/02_users_clients.sql')));
    }

    public function down(): void
    {
        // Явний зворотний порядок без CASCADE: сторонні залежності зупинять відкат.
        DB::unprepared(<<<'SQL'
            ALTER TABLE recipes DROP CONSTRAINT fk_recipes_owner_client;
            ALTER TABLE meal_plans DROP CONSTRAINT fk_meal_plans_owner_client;
            ALTER TABLE client_meal_plan_assignments DROP CONSTRAINT fk_plan_assignments_client;
            ALTER TABLE client_meal_plan_assignments DROP CONSTRAINT fk_plan_assignments_author;
            DROP TABLE client_settings;
            DROP TABLE client_activity_profiles;
            DROP TABLE client_nutrition_profiles;
            DROP TABLE client_onboardings;
            DROP TABLE clients;
            SQL);
    }
};
