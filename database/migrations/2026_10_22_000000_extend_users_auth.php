<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/auth2/00_users.sql')));
    }

    public function down(): void
    {
        // Laravel PostgreSQL виконує міграцію транзакційно; lock усуває гонку перевірки з INSERT.
        DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
        if (DB::table('users')->whereNotNull('email_normalized')->orWhere('auth_version', '<>', 0)->exists()) {
            throw new RuntimeException('Відкат скасовано: users містить auth-дані. Потрібен окремий погоджений план.');
        }
        DB::unprepared('DROP TRIGGER auth2_user_version ON users; DROP FUNCTION auth2_guard_user_version(); ALTER TABLE users DROP COLUMN email_normalized, DROP COLUMN auth_version;');
    }
};
