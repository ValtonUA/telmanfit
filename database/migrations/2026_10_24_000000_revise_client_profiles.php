<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/profiles/00_profiles.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: профілі та архівні дані не видаляються.');
    }
};
