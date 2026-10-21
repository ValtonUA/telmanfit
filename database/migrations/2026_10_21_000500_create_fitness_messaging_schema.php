<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Транзакцією керує Laravel; знімок не містить BEGIN/COMMIT.
        DB::unprepared(file_get_contents(database_path('sql/stage1/04_fitness_messaging.sql')));
    }

    public function down(): void
    {
        // Явний зворотний порядок без CASCADE: сторонні залежності зупинять відкат.
        DB::unprepared(<<<'SQL'
            DROP TABLE client_events;
            DROP TABLE outbound_messages;
            DROP TABLE message_templates;
            DROP TABLE client_fitness_equipment;
            DROP TABLE fitness_equipment;
            SQL);
    }
};
