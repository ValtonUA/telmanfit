<?php

use App\Services\Auth\Restore\LocalDemoEpoch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$epoch = app(LocalDemoEpoch::class)->current();
$identity = DB::selectOne("SELECT current_database() db, current_user usr, current_setting('server_version_num')::int / 10000 major, (SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname=current_user) privileged");
if ($identity->db !== getenv('DB_DATABASE') || $identity->usr !== 'telmanfit_demo_runner' || $identity->major !== 18 || $identity->privileged
    || DB::table('users')->exists() || DB::table('auth_security_runtime')->exists()) {
    throw new RuntimeException('Потрібна нова порожня demo-БД PostgreSQL 18.');
}
DB::transaction(function () use ($epoch): void {
    DB::table('auth_security_runtime')->insert(['id' => 1, 'restore_epoch' => $epoch, 'state' => 'ready',
        'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()')]);
    $id = DB::table('users')->insertGetId(['name' => 'Демонстраційний адміністратор', 'role' => 'admin',
        'email' => 'owner@example.invalid', 'email_normalized' => 'owner@example.invalid',
        'email_verified_at' => now(), 'password' => Hash::make(getenv('DEMO_PASSWORD'))]);
    // Лише запрошення: картку створить штатний registration flow після OTP.
    DB::table('client_invitations')->insert(['token_hash' => hash('sha256', getenv('DEMO_INVITE')),
        'created_by_user_id' => $id, 'coach_user_id' => $id]);
});
echo "Demo bootstrap ready; MFA enrollment required.\n";
