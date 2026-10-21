<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/auth2/05_mfa_recovery_codes.sql')));
    }

    public function down(): void
    {
        // Laravel PostgreSQL виконує міграцію транзакційно; lock усуває гонку перевірки з INSERT.
        DB::statement('LOCK TABLE mfa_recovery_codes IN ACCESS EXCLUSIVE MODE');
        if (DB::table('mfa_recovery_codes')->exists()) {
            throw new RuntimeException('Відкат скасовано: mfa_recovery_codes містить auth-дані. Потрібен окремий погоджений план.');
        }
        DB::unprepared('DROP TABLE mfa_recovery_codes; DROP FUNCTION auth2_guard_recovery();');
    }
};
