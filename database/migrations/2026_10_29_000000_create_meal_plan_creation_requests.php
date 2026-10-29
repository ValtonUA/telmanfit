<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/meal-plans/20_creation_requests.sql')));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: квитанції створення раціонів зберігаються.');
    }
};
