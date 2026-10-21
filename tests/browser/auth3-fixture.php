<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function (Throwable $exception) {
    fwrite(STDERR, 'AUTH3 synthetic fixture failed (details suppressed)'.PHP_EOL);
    exit(1);
});
if (in_array($argv[1] ?? '', ['gate-down', 'gate-up'], true)) {
    DB::statement($argv[1] === 'gate-down' ? 'ALTER TABLE auth_session_states RENAME TO auth3_gate_outage' : 'ALTER TABLE auth3_gate_outage RENAME TO auth_session_states');
    exit;
}
if (($argv[1] ?? '') === 'delete-card') {
    DB::table('clients')->where('user_id', 8320000001)->delete();
    exit;
}
if (($argv[1] ?? '') === 'revoke') {
    DB::transaction(function () {
        DB::table('users')->where('id', 8320000000)->lockForUpdate()->first();
        DB::table('users')->where('id', 8320000000)->increment('auth_version');
    });
    exit;
}
DB::table('users')->insert(['id' => 8320000000, 'name' => 'Тестовий адміністратор AUTH-3',
    'role' => 'admin', 'email' => 'browser-auth3@example.invalid', 'password' => Hash::make('Synthetic-AUTH3-only!')]);
DB::table('users')->insert(['id' => 8320000001, 'name' => 'Синтетичний клієнт AUTH-3', 'role' => 'client']);
DB::table('clients')->insert(['user_id' => 8320000001, 'coach_user_id' => 8320000000, 'status' => 'pending']);
