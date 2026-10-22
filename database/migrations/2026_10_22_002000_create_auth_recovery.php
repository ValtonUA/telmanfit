<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '5min'");
        DB::unprepared(file_get_contents(database_path('sql/auth6b/00_recovery.sql')));
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Навіть порожній down потребує сумісного artifact; populated — лише forward fix.
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE auth_recovery_principals, auth_recovery_keys, auth_recovery_cases, auth_recovery_approvals, auth_recovery_grants, auth_recovery_gates, auth_recovery_events IN ACCESS EXCLUSIVE MODE');
            foreach (['principals', 'keys', 'cases', 'approvals', 'grants', 'gates', 'events'] as $table) {
                if (DB::table('auth_recovery_'.$table)->exists()) {
                    throw new RuntimeException('Відкат скасовано: recovery history/gates мають залишитися.');
                }
            }
            DB::unprepared(file_get_contents(database_path('sql/auth6b/01_empty_down.sql')));
        });
    }
};
