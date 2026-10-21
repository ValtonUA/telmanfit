<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/auth2/04_user_totp_factors.sql')));
    }

    public function down(): void
    {
        // Laravel PostgreSQL виконує міграцію транзакційно; lock усуває гонку перевірки з INSERT.
        DB::statement('LOCK TABLE user_totp_factors IN ACCESS EXCLUSIVE MODE');
        if (DB::table('user_totp_factors')->exists()) {
            throw new RuntimeException('Відкат скасовано: user_totp_factors містить auth-дані. Потрібен окремий погоджений план.');
        }
        DB::unprepared('DROP TABLE user_totp_factors; DROP FUNCTION auth2_guard_totp_step();');
    }
};
