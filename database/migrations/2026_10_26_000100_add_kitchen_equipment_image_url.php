<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/presentation/01_kitchen_equipment_image.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: image_url зберігає метадані зображення кухонного обладнання.');
    }
};
