<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/meal_plans/00_selectors.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: recipe selectors та історія споживання зберігаються.');
    }
};
