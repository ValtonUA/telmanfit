<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '5min'");
        DB::unprepared(file_get_contents(database_path('sql/auth6b4/00_outbox.sql')));
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE auth_security_notice_outbox IN ACCESS EXCLUSIVE MODE');
            if (DB::table('auth_security_notice_outbox')->exists()) {
                throw new RuntimeException('Відкат скасовано: security notice history потребує forward fix.');
            }
            DB::unprepared('DROP FUNCTION auth_notice_claim(integer); DROP FUNCTION auth_notice_ack(uuid,uuid,text); DROP TABLE auth_security_notice_outbox; DROP FUNCTION auth_notice_guard(); DROP FUNCTION auth_notice_audit();');
        });
    }
};
