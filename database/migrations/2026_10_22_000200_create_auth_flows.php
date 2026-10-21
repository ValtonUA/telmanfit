<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/auth2/02_auth_flows.sql')));
    }

    public function down(): void
    {
        // Laravel PostgreSQL виконує міграцію транзакційно; lock усуває гонку перевірки з INSERT.
        DB::statement('LOCK TABLE auth_flows IN ACCESS EXCLUSIVE MODE');
        if (DB::table('auth_flows')->exists()) {
            throw new RuntimeException('Відкат скасовано: auth_flows містить auth-дані. Потрібен окремий погоджений план.');
        }
        DB::unprepared('DROP TABLE auth_flows; DROP FUNCTION auth2_guard_flow();');
    }
};
