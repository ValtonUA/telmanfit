<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '5min'");
        DB::unprepared(file_get_contents(database_path('sql/auth6b5/00_retention.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('B5 restore fence/history requires a gate-aware forward fix.');
    }
};
