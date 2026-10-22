<?php

use App\Services\Auth\MfaService;
use App\Services\Auth\Notices\NoticeType;
use App\Services\Auth\Notices\NoticeWriter;
use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\RestoreFence;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Auth\Restore\SyntheticRestore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    Artisan::call('up');
    fwrite(STDERR, 'B5 snapshot drill failed: '.get_class($e).' line '.$e->getLine()."\n");
    exit(1);
});
$container = getenv('B5_SYNTHETIC_CONTAINER');
$dump = getenv('B5_SYNTHETIC_DUMP');
if (! preg_match('/^[a-f0-9]{64}$/D', $container) || dirname($dump) !== dirname(getenv('DB_HOST'))) {
    throw new RuntimeException('synthetic_dump_guard');
}
function drillCommand(array $args, ?string $input = null, ?string $output = null): void
{
    $pipes = [];
    $p = proc_open($args, [0 => $input ? ['file', $input, 'r'] : ['pipe', 'r'], 1 => $output ? ['file', $output, 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }
    if (isset($pipes[1])) {
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException('synthetic_dump_command_failed');
    }
}
// Окремий external adapter лишається в пам'яті orchestrator, поза pg_dump.
$external = new SyntheticEpoch;
app()->instance(ExternalEpoch::class, $external);
$fence = app(RestoreFence::class);
$fence->assert();
$user = DB::table('users')->insertGetId(['name' => 'Synthetic PITR fixture', 'role' => 'client']);
$selector = (string) Str::uuid();
$codeHash = password_hash('synthetic', PASSWORD_ARGON2ID);
DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
    'confirmed_at' => DB::raw('clock_timestamp()'), 'last_used_step' => 80000000]);
DB::table('mfa_recovery_codes')->insert(['user_id' => $user, 'selector' => $selector, 'batch_id' => (string) Str::uuid(), 'code_hash' => $codeHash]);
$public = (string) Str::uuid();
$flow = DB::table('auth_flows')->insertGetId(['public_id' => $public, 'user_id' => $user, 'auth_version_at_start' => 0,
    'browser_binding_hash' => str_repeat('b', 64), 'audience' => 'client', 'purpose' => 'login', 'expires_at' => DB::raw("clock_timestamp()+interval '5 minutes'")]);
$challenge = DB::table('auth_challenges')->insertGetId(['public_id' => (string) Str::uuid(), 'flow_id' => $flow, 'generation' => 1,
    'email_normalized' => 'synthetic@example.invalid', 'code_mac' => str_repeat('c', 64), 'key_version' => 'test_v1', 'max_attempts' => 5,
    'expires_at' => DB::raw("clock_timestamp()+interval '5 minutes'")]);
$session = (string) Str::uuid();
DB::table('sessions')->insert(['id' => $session, 'user_id' => $user, 'payload' => 'synthetic', 'last_activity' => time()]);
DB::table('auth_session_states')->insert(['session_id' => $session, 'user_id' => $user, 'audience' => 'client', 'browser_binding_hash' => str_repeat('b', 64),
    'auth_version' => 0, 'primary_method' => 'email_otp', 'primary_verified_at' => DB::raw('clock_timestamp()'), 'absolute_expires_at' => DB::raw("clock_timestamp()+interval '7 days'")]);
$notice = DB::transaction(fn () => app(NoticeWriter::class)->write($user, NoticeType::MfaChanged, ['current' => 'synthetic@example.invalid']));
$holdPrincipal = (string) Str::uuid();
DB::table('auth_recovery_principals')->insert(['id' => $holdPrincipal, 'authority' => 'reviewer', 'identity_record_digest' => str_repeat('d', 64), 'created_at' => DB::raw('clock_timestamp()')]);
require __DIR__.'/auth6b5-grant-fixture.php';
$snapshotTime = microtime(true);
drillCommand(['docker', 'exec', $container, 'pg_dump', '-U', 'postgres', '-Fc', getenv('DB_DATABASE')], output: $dump);
$digest = hash_file('sha256', $dump);
// Події після backup: grant/OTP/code витрачено, version і factor обернуто.
DB::transaction(fn () => DB::select('SELECT auth6b_exchange(?::uuid,?,?,?::uuid)', [$grantCase, $grantHash, str_repeat('a', 64), (string) Str::uuid()]));
DB::table('auth_challenges')->where('id', $challenge)->update(['consumed_at' => DB::raw('clock_timestamp()')]);
DB::table('mfa_recovery_codes')->where('selector', $selector)->update(['used_at' => DB::raw('clock_timestamp()')]);
DB::table('users')->where('id', $user)->increment('auth_version');
DB::table('user_totp_factors')->where('user_id', $user)->update(['secret_ciphertext' => str_repeat('n', 100), 'last_used_step' => 80000001]);
$holdId = (string) Str::uuid();
DB::table('auth_retention_holds')->insert(['id' => $holdId, 'subject_user_id' => $user, 'reason_code' => 'incident', 'created_by_principal_id' => $holdPrincipal]);
$currentHold = (array) DB::table('auth_retention_holds')->where('id', $holdId)->first();
DB::table('auth_recovery_principals')->where('id', $holdPrincipal)->update(['revoked_at' => DB::raw('clock_timestamp()')]);
$currentRevocation = DB::table('auth_recovery_principals')->where('id', $holdPrincipal)->value('revoked_at');
Artisan::call('down');
DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'maintenance']);
$external->epoch = (string) Str::uuid();
if ($digest !== hash_file('sha256', $dump)) {
    throw new RuntimeException('synthetic_backup_checksum');
}
$infra = hrtime(true);
DB::disconnect();
drillCommand(['docker', 'exec', '-i', $container, 'pg_restore', '-U', 'postgres', '--clean', '--if-exists', '--exit-on-error', '-d', getenv('DB_DATABASE')], input: $dump);
DB::reconnect();
$infrastructureSeconds = (hrtime(true) - $infra) / 1e9;
// Snapshot справді воскресив DB bytes: зовнішній fence не дає їм authority.
if (DB::table('auth_recovery_grants')->where('id', $grantId)->value('exchanged_at') !== null
    || DB::table('mfa_recovery_codes')->where('selector', $selector)->value('used_at') !== null
    || DB::table('user_totp_factors')->where('user_id', $user)->value('last_used_step') !== 80000000) {
    throw new RuntimeException('snapshot_not_replayed');
}
try {
    $fence->assert();
    throw new LogicException('stale_snapshot_opened');
} catch (RuntimeException) {
}
$reconcile = hrtime(true);
app(SyntheticRestore::class)->reconcile($external, array_fill_keys(['backup', 'wal', 'keys', 'registry', 'holds', 'revocations', 'audit'], true) + ['hold_rows' => [$currentHold], 'principal_revocations' => [$holdPrincipal => $currentRevocation], 'key_revocations' => []]);
$reconciliationSeconds = (hrtime(true) - $reconcile) / 1e9;
if (DB::table('auth_recovery_grants')->where('id', $grantId)->value('revoked_at') === null
    || ! DB::table('auth_retention_holds')->where('id', $holdId)->exists()
    || DB::table('auth_recovery_principals')->where('id', $holdPrincipal)->value('revoked_at') === null
    || DB::table('mfa_recovery_codes')->where('selector', $selector)->value('revoked_at') === null
    || DB::table('auth_challenges')->where('id', $challenge)->value('invalidated_at') === null
    || ! DB::table('auth_audit_events')->where('event_type', 'restore.session_scrubbed')->where('subject_user_id', $user)->where('session_fingerprint', hash('sha256', $session))->exists()
    || DB::table('sessions')->where('id', $session)->exists()
    || MfaService::active(DB::table('user_totp_factors')->where('user_id', $user)->first())
    || DB::table('auth_security_notice_outbox')->where('request_id', $notice)->value('recipient_ciphertext') !== null) {
    throw new RuntimeException('snapshot_resurrection');
}
try {
    DB::transaction(fn () => DB::select('SELECT auth6b_exchange(?::uuid,?,?,?::uuid)', [$grantCase, $grantHash, str_repeat('a', 64), (string) Str::uuid()]));
    throw new LogicException('restored_grant_replayed');
} catch (QueryException) {
}
$result = ['synthetic_only' => true, 'pg_major' => 18, 'snapshot_age_seconds' => microtime(true) - $snapshotTime,
    'infrastructure_restore_seconds' => $infrastructureSeconds, 'closed_reconciliation_seconds' => $reconciliationSeconds,
    'restricted_security_service_seconds' => null, 'business_access_seconds' => null,
    'access_state' => 'recovery_required', 'rpo_target_seconds' => 900, 'production_sla' => false];
file_put_contents(base_path('docs/evidence/auth6b-b5/restore.json'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
unlink($dump);
Artisan::call('up');
// Повертаємо лише test fence для наступних тестів, capabilities лишаються revoked.
DB::table('auth_security_runtime')->where('id', 1)->update(['restore_epoch' => SyntheticEpoch::INITIAL, 'state' => 'ready', 'issuance_not_before' => DB::raw('clock_timestamp()')]);
echo "B5 real synthetic pg_dump/pg_restore snapshot drill PASS: spent OTP/code and rotated factor restored as bytes, access denied, old session/outbox eliminated; business access remains closed\n";
