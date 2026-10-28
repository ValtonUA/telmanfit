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

        DB::unprepared(file_get_contents(database_path('sql/cooking/00_templates.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: опубліковані кухонні інструкції зберігаються.');
    }
};
