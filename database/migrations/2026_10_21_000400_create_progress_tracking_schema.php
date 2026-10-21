<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Транзакцією керує Laravel; знімок не містить BEGIN/COMMIT.
        DB::unprepared(file_get_contents(database_path('sql/stage1/03_progress_tracking.sql')));
    }

    public function down(): void
    {
        // Явний зворотний порядок без CASCADE: сторонні залежності зупинять відкат.
        DB::unprepared(<<<'SQL'
            DROP TABLE client_progress_photos;
            DROP TABLE client_metrics;
            DROP TABLE client_weekly_reports;
            DROP TABLE client_metric_assignments;
            DROP TABLE metrics;
            SQL);
    }
};
