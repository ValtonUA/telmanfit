<?php

use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Auth\Restore\SyntheticRestore;
use App\Support\Session\ContextDatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'B5 concurrent assertion failed: '.get_class($e).' line '.$e->getLine()."\n");
    exit(1);
});
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='auth6b5_worker'");
    if ($input['action'] === 'gc') {
        config(['mfa.enabled' => true]);
        $handler = new ContextDatabaseSessionHandler(DB::connection(), 'sessions', 720, app());
        echo $handler->gc(43200);
        exit;
    }
    if ($input['action'] === 'cleanup') {
        DB::statement('SET ROLE telmanfit_retention_executor');
        echo DB::selectOne('SELECT auth_retention_cleanup(?::uuid,?,?) n', [$input['run'], $input['batch'], $input['kind']])->n;
        exit;
    }
    if ($input['action'] === 'restore-stage') {
        $external = new SyntheticEpoch($input['epoch']);
        app()->instance(ExternalEpoch::class, $external);
        $proofs = array_fill_keys(['backup', 'wal', 'keys', 'registry', 'holds', 'revocations', 'audit'], true) + ['hold_rows' => [], 'principal_revocations' => [], 'key_revocations' => []];
        app(SyntheticRestore::class)->reconcile($external, $proofs, function ($stage) use ($input) {
            if ($stage === $input['stage']) {
                echo "ready\n";
                fflush(STDOUT);
                fgets(STDIN);
            }
        });
        exit;
    }
    DB::beginTransaction();
    DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
    try {
        $n = DB::selectOne('SELECT auth_retention_purge_audit(?::uuid,1,?::uuid,?::bigint[]) n', [$input['run'], $input['manifest'], '{'.$input['row'].'}'])->n;
        if ($input['action'] === 'after') {
            DB::commit();
        }
        if (in_array($input['action'], ['before', 'after'], true)) {
            echo "ready\n";
            fflush(STDOUT);
            fgets(STDIN);
            exit;
        }
        DB::commit();
        echo 'purged:'.$n;
    } catch (Throwable) {
        DB::rollBack();
        echo 'denied';
    }
    exit;
}
function spawnB5(array $input): array
{
    $p = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input)."\n");
    fflush($pipes[0]);

    return [$p, $pipes];
}
function finishB5(array $worker): string
{
    [$p, $pipes] = $worker;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0 || $err !== '') {
        throw new RuntimeException('worker_failed');
    }

    return $out;
}
function waitB5(): void
{
    for ($i = 0; $i < 100; $i++) {
        DB::select('SELECT pg_stat_clear_snapshot()');
        if (DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE application_name='auth6b5_worker' AND wait_event_type='Lock'")->n > 0) {
            return;
        }
        usleep(30000);
    }
    throw new RuntimeException('lock_wait_not_observed');
}
function fixtureB5(): array
{
    $row = DB::table('auth_audit_events')->insertGetId(['occurred_at' => DB::raw("clock_timestamp()-interval '181 days'"),
        'event_type' => 'test.synthetic', 'outcome' => 'success', 'request_id' => (string) Str::uuid()]);
    $r = DB::selectOne("SELECT occurred_at,encode(sha256(convert_to(jsonb_agg(to_jsonb(a))::text,'UTF8')),'hex') digest FROM auth_audit_events a WHERE id=? GROUP BY occurred_at", [$row]);
    $manifest = (string) Str::uuid();
    DB::table('auth_archive_manifests')->insert(['id' => $manifest, 'entity_type' => 'audit', 'first_id' => $row, 'last_id' => $row,
        'first_occurred_at' => $r->occurred_at, 'last_occurred_at' => $r->occurred_at, 'row_count' => 1, 'content_digest' => $r->digest,
        'object_ref' => (string) Str::uuid(), 'exported_at' => DB::raw('clock_timestamp()'), 'verified_at' => DB::raw('clock_timestamp()'),
        'retain_until' => DB::raw("clock_timestamp()+interval '365 days'"), 'request_id' => (string) Str::uuid()]);
    $run = (string) Str::uuid();
    DB::table('auth_retention_runs')->insert(['id' => $run, 'mode' => 'apply', 'policy_version' => 1,
        'cutoff' => DB::raw('clock_timestamp()'), 'state' => 'running', 'request_id' => (string) Str::uuid()]);

    return compact('row', 'manifest', 'run');
}
$p = [];
foreach ([1, 2] as $i) {
    $p[$i] = (string) Str::uuid();
    DB::table('auth_recovery_principals')->insert(['id' => $p[$i], 'authority' => 'reviewer', 'identity_record_digest' => str_repeat('b', 64), 'created_at' => DB::raw('clock_timestamp()')]);
}
foreach ([true, false] as $commit) {
    $f = fixtureB5();
    DB::beginTransaction();
    $hold = (string) Str::uuid();
    DB::table('auth_retention_holds')->insert(['id' => $hold, 'audit_from' => DB::raw("clock_timestamp()-interval '182 days'"),
        'audit_to' => DB::raw("clock_timestamp()-interval '180 days'"), 'reason_code' => 'legal', 'created_by_principal_id' => $p[1]]);
    $worker = spawnB5($f + ['action' => 'purge']);
    waitB5();
    $commit ? DB::commit() : DB::rollBack();
    if (finishB5($worker) !== ($commit ? 'denied' : 'purged:1')) {
        throw new RuntimeException('hold_create_linearization');
    }
    if ($commit) {
        foreach ([false, true] as $release) {
            DB::beginTransaction();
            DB::table('auth_retention_holds')->where('id', $hold)->update(['released_at' => DB::raw('clock_timestamp()'), 'released_by_principal_id' => $p[2]]);
            $worker = spawnB5($f + ['action' => 'purge']);
            waitB5();
            $release ? DB::commit() : DB::rollBack();
            if (finishB5($worker) !== ($release ? 'purged:1' : 'denied')) {
                throw new RuntimeException('hold_release_linearization');
            }
        }
    }
}
echo "B5 hold create/release concurrent purge PASS: independent PG process, observed policy-lock waits, commit/rollback\n";
foreach (['before', 'after'] as $when) {
    $f = fixtureB5();
    [$process, $pipes] = spawnB5($f + ['action' => $when]);
    stream_set_timeout($pipes[1], 10);
    if (fgets($pipes[1]) !== "ready\n") {
        throw new RuntimeException('crash_barrier');
    }
    proc_terminate($process, 15);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    if (DB::table('auth_audit_events')->where('id', $f['row'])->exists() !== ($when === 'before')
        || DB::table('auth_retention_batches')->where('run_id', $f['run'])->count() !== ($when === 'before' ? 0 : 1)
        || finishB5(spawnB5($f + ['action' => 'purge'])) !== 'purged:1') {
        throw new RuntimeException('crash_atomic_retry');
    }
}
echo "B5 process crash PASS: SIGTERM before/after commit, atomic rows/batch/audit, idempotent fresh-process retry\n";
$session = (string) Str::uuid();
DB::table('sessions')->insert(['id' => $session, 'payload' => 'synthetic', 'last_activity' => time() - 50000]);
DB::beginTransaction();
DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,606))', [hash('sha256', $session)]);
if (finishB5(spawnB5(['action' => 'gc'])) !== '0' || ! DB::table('sessions')->where('id', $session)->exists()) {
    throw new RuntimeException('session_lock_gc');
}
DB::table('sessions')->where('id', $session)->update(['last_activity' => time()]);
DB::commit();
if (finishB5(spawnB5(['action' => 'gc'])) !== '0' || ! DB::table('sessions')->where('id', $session)->exists()) {
    throw new RuntimeException('session_save_recheck');
}
echo "B5 session save/GC PASS: B1 advisory lock skip and TTL recheck after refresh\n";

app()->maintenanceMode()->activate([]);
try {
    $proofs = array_fill_keys(['backup', 'wal', 'keys', 'registry', 'holds', 'revocations', 'audit'], true) + ['hold_rows' => [], 'principal_revocations' => [], 'key_revocations' => []];
    foreach (['preflight', 'manifests', 'versions', 'capabilities', 'factors', 'delivery', 'reconciled', 'committed'] as $stage) {
        $epoch = (string) Str::uuid();
        $version = (int) DB::table('users')->max('auth_version');
        [$process,$pipes] = spawnB5(['action' => 'restore-stage', 'stage' => $stage, 'epoch' => $epoch]);
        stream_set_timeout($pipes[1], 15);
        if (fgets($pipes[1]) !== "ready\n") {
            throw new RuntimeException('restore_crash_barrier');
        }
        proc_terminate($process, 15);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        if (DB::table('auth_security_runtime')->value('state') === 'ready' || (int) DB::table('users')->max('auth_version') !== $version + ($stage === 'committed' ? 1 : 0)) {
            throw new RuntimeException('restore_process_crash_not_closed');
        }
        $external = new SyntheticEpoch($epoch);
        app()->instance(ExternalEpoch::class, $external);
        app(SyntheticRestore::class)->reconcile($external, $proofs);
        $once = DB::table('users')->max('auth_version');
        app(SyntheticRestore::class)->reconcile($external, $proofs);
        if (DB::table('users')->max('auth_version') !== $once || DB::table('auth_security_runtime')->value('state') !== 'recovery_required') {
            throw new RuntimeException('restore_process_retry_not_idempotent');
        }
    }
} finally {
    app()->maintenanceMode()->deactivate();
    DB::table('auth_security_runtime')->where('id', 1)->update(['restore_epoch' => SyntheticEpoch::INITIAL, 'state' => 'ready', 'issuance_not_before' => DB::raw('clock_timestamp()')]);
}
echo "B5 restore SIGTERM drill PASS: eight crash stages, transactional rollback/durable closed fence, fresh-process idempotent retry\n";

// Той самий user lock, що й MFA/code writers: cleanup пропускає зайнятий account.
$user = DB::table('users')->insertGetId(['name' => 'Synthetic MFA cleanup race', 'role' => 'client']);
DB::table('user_totp_factors')->insert(['user_id' => $user, 'pending_secret_ciphertext' => str_repeat('p', 100), 'pending_key_version' => 'test_v1',
    'pending_expires_at' => DB::raw("clock_timestamp()-interval '1 second'"), 'created_at' => DB::raw("clock_timestamp()-interval '1 hour'")]);
$run = fixtureB5()['run'];
DB::beginTransaction();
DB::table('users')->where('id', $user)->lockForUpdate()->first();
if (finishB5(spawnB5(['action' => 'cleanup', 'run' => $run, 'batch' => 1, 'kind' => 'pending_totp'])) !== '0') {
    throw new RuntimeException('pending_lock_not_skipped');
}
DB::table('user_totp_factors')->where('user_id', $user)->update(['secret_ciphertext' => str_repeat('p', 100), 'key_version' => 'test_v1', 'confirmed_at' => DB::raw('clock_timestamp()'),
    'last_used_step' => 12345, 'pending_secret_ciphertext' => null, 'pending_key_version' => null, 'pending_expires_at' => null, 'updated_at' => DB::raw('clock_timestamp()')]);
DB::commit();
if (finishB5(spawnB5(['action' => 'cleanup', 'run' => $run, 'batch' => 2, 'kind' => 'pending_totp'])) !== '0' || DB::table('user_totp_factors')->where('user_id', $user)->value('last_used_step') !== 12345) {
    throw new RuntimeException('confirmed_pending_race');
}
$batch = (string) Str::uuid();
$code = DB::table('mfa_recovery_codes')->insertGetId(['user_id' => $user, 'selector' => (string) Str::uuid(), 'batch_id' => $batch, 'code_hash' => password_hash('synthetic', PASSWORD_ARGON2ID),
    'created_at' => DB::raw("clock_timestamp()-interval '400 days'"), 'used_at' => DB::raw("clock_timestamp()-interval '366 days'")]);
DB::beginTransaction();
DB::table('users')->where('id', $user)->lockForUpdate()->first();
if (finishB5(spawnB5(['action' => 'cleanup', 'run' => $run, 'batch' => 3, 'kind' => 'codes'])) !== '0') {
    throw new RuntimeException('code_lock_not_skipped');
}
DB::table('user_totp_factors')->where('user_id', $user)->update(['recovery_batch_id' => $batch]);
DB::commit();
if (finishB5(spawnB5(['action' => 'cleanup', 'run' => $run, 'batch' => 4, 'kind' => 'codes'])) !== '0' || ! DB::table('mfa_recovery_codes')->where('id', $code)->exists()) {
    throw new RuntimeException('current_batch_recheck');
}
echo "B5 MFA/code cleanup concurrency PASS: busy account skip, confirmed material and current batch rechecked after commit, watermark preserved\n";
$notice = (string) Str::uuid();
DB::table('auth_security_notice_outbox')->insert(['id' => $notice, 'request_id' => (string) Str::uuid(), 'subject_user_id' => $user, 'notice_type' => 'mfa_changed', 'contract_version' => 1,
    'recipient_slot' => 'current', 'recipient_ciphertext' => str_repeat('s', 100), 'recipient_key_version' => 'test_v1', 'created_at' => DB::raw('clock_timestamp()'),
    'expires_at' => DB::raw("clock_timestamp()+interval '1 hour'"), 'next_attempt_at' => DB::raw('clock_timestamp()')]);
if (finishB5(spawnB5(['action' => 'cleanup', 'run' => $run, 'batch' => 5, 'kind' => 'outbox'])) !== '0') {
    throw new RuntimeException('pending_notice_deleted');
}
$claims = DB::select('SELECT * FROM auth_notice_claim(50)');
$claim = collect($claims)->firstWhere('id', $notice);
if (! $claim || finishB5(spawnB5(['action' => 'cleanup', 'run' => $run, 'batch' => 6, 'kind' => 'outbox'])) !== '0') {
    throw new RuntimeException('leased_notice_deleted');
}
DB::select("SELECT auth_notice_ack(?::uuid,?::uuid,'sent')", [$notice, $claim->lease_token]);
if (finishB5(spawnB5(['action' => 'cleanup', 'run' => $run, 'batch' => 7, 'kind' => 'outbox'])) !== '0' || DB::table('auth_security_notice_outbox')->where('id', $notice)->value('recipient_ciphertext') !== null) {
    throw new RuntimeException('terminal_notice_retention_or_scrub');
}
echo "B5 outbox claim/cleanup PASS: independent cleanup preserves pending/leased/young terminal rows; ack scrubs recipient\n";
