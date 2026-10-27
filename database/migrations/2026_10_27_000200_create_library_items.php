<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(file_get_contents(database_path('sql/library/01_library_items.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: бібліотека містить авторські матеріали.');
    }
};
