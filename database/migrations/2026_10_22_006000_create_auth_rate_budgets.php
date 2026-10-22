<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout='5s'");
        DB::statement("SET LOCAL statement_timeout='5min'");
        DB::unprepared(file_get_contents(database_path('sql/auth6b6/00_budgets.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Rate budgets require a reviewed forward transition; active protection cannot be reset.');
    }
};
