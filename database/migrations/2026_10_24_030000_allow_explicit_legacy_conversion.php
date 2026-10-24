<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/recipes/02_legacy_conversion.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: конвертовані рецепти та provenance зберігаються.');
    }
};
