<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/auth2/01_client_invitations.sql')));
    }

    public function down(): void
    {
        // Laravel PostgreSQL виконує міграцію транзакційно; lock усуває гонку перевірки з INSERT.
        DB::statement('LOCK TABLE client_invitations IN ACCESS EXCLUSIVE MODE');
        if (DB::table('client_invitations')->exists()) {
            throw new RuntimeException('Відкат скасовано: client_invitations містить auth-дані. Потрібен окремий погоджений план.');
        }
        DB::unprepared('DROP TABLE client_invitations; DROP FUNCTION auth2_guard_invitation();');
    }
};
