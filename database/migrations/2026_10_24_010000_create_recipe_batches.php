<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/recipes/00_batch.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: frozen recipe batches не видаляються.');
    }
};
