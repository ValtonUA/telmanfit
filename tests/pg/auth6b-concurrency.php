<?php

use App\Mail\EmailOtp;
use App\Services\Auth\MfaService;
use App\Services\Auth\Recovery\RecoveryBoundary;
use App\Services\Auth\Recovery\RecoveryDenied;
use App\Services\Auth\Recovery\RecoveryService;
use App\Services\Auth\Recovery\RecoverySignatures;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Auth6B\FixtureBoundary;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'B3 concurrency failed: '.get_class($error).' at '.$error->getLine().': '.$error->getMessage()."\n");
    exit(1);
});
config(['auth4.enabled' => true, 'auth4.key_version' => 'test_v1',
    'auth4.keys.test_v1' => 'synthetic-concurrency-hmac-key-32minimum', 'auth4.rate_key' => 'synthetic-concurrency-rate-key-32minimum',
    'mfa.enabled' => true, 'session.driver' => 'database', 'cache.default' => 'database', 'mail.default' => 'array',
    'queue.default' => 'sync', 'mfa.flow_seconds' => 600, 'mfa.session_seconds' => 28800, 'mfa.attempts' => 10,
    'mfa.window' => 1, 'mfa.recovery_count' => 3, 'mfa.key_version' => 'test_v1']);
app()->instance(RecoveryBoundary::class, new FixtureBoundary);
$service = app(RecoveryService::class);
app()->instance(RecoveryService::class, $service);
Mail::fake();

if (($argv[1] ?? '') === 'worker') {
    $in = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='auth6b_race_worker'");
    try {
        match ($in['action']) {
            'approve' => $service->approve($in['case'], $in['approval']),
            'exchange' => $service->exchange($in['case'], $in['token'], $in['binding'], $in['ip']),
            'finalize' => app(MfaService::class)->confirmRecoveryEnrollment($in['case'], $in['binding'], $in['code'], $in['ip']),
            'close' => $service->close($in['case'], 'cancelled'),
            'revoke' => DB::transaction(function () use ($in) {
                DB::select('SELECT pg_advisory_xact_lock(6060303)');
                DB::table('auth_recovery_keys')->where('id', $in['key'])->update(['revoked_at' => now()]);
            }),
        };
        echo 'accepted';
    } catch (RecoveryDenied|QueryException) {
        echo 'denied';
    } catch (Throwable $error) {
        fwrite(STDERR, get_class($error).' at '.$error->getLine());
        exit(2);
    }
    exit;
}

function raceB3(array $first, ?array $second = null): void
{
    DB::beginTransaction();
    $revocation = $first['action'] === 'revoke' || ($second['action'] ?? '') === 'revoke';
    DB::select($revocation ? 'SELECT pg_advisory_xact_lock(6060303)' : 'SELECT pg_advisory_xact_lock(4040404)');
    $workers = [];
    foreach ([$first, $second ?? $first] as $input) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $waiting = false;
    for ($i = 0; $i < 100; $i++) {
        DB::select('SELECT pg_stat_clear_snapshot()');
        if (DB::selectOne("SELECT count(DISTINCT pid) n FROM pg_stat_activity WHERE application_name='auth6b_race_worker' AND wait_event_type='Lock'")->n === 2) {
            $waiting = true;
            break;
        }
        usleep(40000);
    }
    DB::commit();
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') {
            throw new RuntimeException('B3 worker error: '.$error);
        }
    }
    sort($results);
    if (! $waiting || ($results !== ['accepted', 'denied'] && ! ($revocation && $results === ['accepted', 'accepted']))) {
        throw new RuntimeException('B3 requires two blocked PIDs and exactly one winner: '.implode(',', $results));
    }
}

function caseB3(bool $held = true): array
{
    $user = DB::table('users')->insertGetId(['name' => 'B3 synthetic race', 'role' => 'admin',
        'email' => Str::uuid().'@example.invalid', 'email_verified_at' => now()]);
    $u = DB::table('users')->where('id', $user)->first();
    DB::table('users')->where('id', $user)->update(['email_normalized' => $u->email]);
    $principals = $keys = [];
    for ($i = 0; $i < 4; $i++) {
        $id = (string) Str::uuid();
        DB::table('auth_recovery_principals')->insert(['id' => $id, 'linked_user_id' => $i === 0 ? $user : null,
            'authority' => $i < 2 ? 'operator' : 'trustee', 'identity_record_digest' => hash('sha256', $id), 'created_at' => now()->subDays(5)]);
        $principals[] = $id;
        if ($i >= 2) {
            $pair = sodium_crypto_sign_keypair();
            $public = sodium_crypto_sign_publickey($pair);
            $key = (string) Str::uuid();
            DB::table('auth_recovery_keys')->insert(['id' => $key, 'principal_id' => $id, 'public_key' => DB::raw("decode('".bin2hex($public)."','hex')"),
                'fingerprint' => hash('sha256', $public), 'registry_version' => 1, 'valid_from' => now()->subDays(2), 'valid_until' => now()->addDays(5)]);
            $keys[] = [$id, $key, sodium_crypto_sign_secretkey($pair)];
        }
    }
    $clock = now();
    $case = (object) ['id' => (string) Str::uuid(), 'target_user_id' => $user, 'target_principal_id' => $principals[0], 'initiator_principal_id' => $principals[1],
        'operator_principal_id' => null, 'target_role' => 'admin', 'action' => 'replace_mfa', 'replace_admin_password' => false,
        'expected_auth_version' => 0, 'registry_version' => 1, 'policy_version' => 1, 'deployment_id' => 'synthetic',
        'restore_epoch' => '60605050-0000-4000-8000-000000000001', 'intent_nonce' => (string) Str::uuid(),
        'replacement_email_mac' => null, 'created_at' => $clock->copy()->subHours(25)->format('Y-m-d H:i:s.uP'),
        'expires_at' => $clock->copy()->addHours(47)->format('Y-m-d H:i:s.uP'), 'reason_code' => 'lost_mfa'];
    $signatures = app(RecoverySignatures::class);
    $case->intent_digest = hash('sha256', $signatures->intent($case));
    DB::table('auth_recovery_cases')->insert((array) $case);
    $approvals = [];
    foreach ($keys as [$p, $k, $secret]) {
        $a = ['principal_id' => $p, 'key_id' => $k, 'evidence_digest' => hash('sha256', 'synthetic-evidence'),
            'verification_receipt_id' => (string) Str::uuid(), 'expires_at' => now()->addHours($held ? 23 : 46)->format('Y-m-d H:i:s.uP')];
        $a['signature'] = base64_encode(sodium_crypto_sign_detached($signatures->envelope($case, $a), $secret));
        $approvals[] = $a;
        if ($held) {
            DB::table('auth_recovery_approvals')->insert(['case_id' => $case->id, 'principal_id' => $p, 'key_id' => $k, 'intent_digest' => $case->intent_digest,
                'evidence_digest' => $a['evidence_digest'], 'signature' => DB::raw("decode('".bin2hex(base64_decode($a['signature']))."','hex')"),
                'verification_receipt_id' => $a['verification_receipt_id'], 'verified_at' => now()->subHours(24)->subMinutes(59), 'expires_at' => $a['expires_at']]);
        }
    }
    if ($held) {
        DB::table('auth_recovery_cases')->where('id', $case->id)->update(['state' => 'hold', 'hold_until' => now()->subMinute()]);
    }

    return [$case, $approvals];
}

[$case, $approvals] = caseB3(false);
raceB3(['action' => 'approve', 'case' => $case->id, 'approval' => $approvals[0]]);
if (DB::table('auth_recovery_approvals')->where('case_id', $case->id)->count() !== 1) {
    throw new RuntimeException('Duplicate approval counted');
}
echo "B3 duplicate approval: two blocked PIDs, one immutable row PASS\n";

foreach (['finalize', 'close', 'mixed', 'revoke'] as $mode) {
    [$case, $approvals] = caseB3();
    $token = $service->issue($case->id)->reveal();
    $binding = bin2hex(random_bytes(32));
    $ip = '192.0.2.'.random_int(50, 200);
    if ($mode === 'finalize') {
        raceB3(['action' => 'exchange', 'case' => $case->id, 'token' => $token, 'binding' => $binding, 'ip' => $ip]);
    } else {
        $service->exchange($case->id, $token, $binding, $ip);
    }
    $challenge = $service->requestPrimary($case->id, $binding, $ip);
    preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);
    $service->verifyPrimary($case->id, $binding, $challenge, $match[1], $ip);
    $secret = app(MfaService::class)->beginRecoveryEnrollment($case->id, $binding)->reveal();
    $finalize = ['action' => 'finalize', 'case' => $case->id, 'binding' => $binding, 'code' => (new Google2FA)->oathTotp($secret, intdiv(time(), 30)), 'ip' => $ip];
    $close = ['action' => 'close', 'case' => $case->id];
    raceB3($mode === 'close' ? $close : $finalize, $mode === 'mixed' ? $close : ($mode === 'revoke' ? ['action' => 'revoke', 'key' => $approvals[0]['key_id']] : null));
    $state = DB::table('auth_recovery_cases')->where('id', $case->id)->value('state');
    if (! in_array($state, $mode === 'revoke' ? ['completed', 'enrolling'] : ['completed', 'cancelled'], true) || ($mode === 'finalize' && $state !== 'completed')
        || ($mode === 'close' && $state !== 'cancelled')) {
        throw new RuntimeException('B3 terminal invariant');
    }
    if ($mode === 'revoke') {
        if (DB::table('auth_recovery_keys')->where('id', $approvals[0]['key_id'])->value('revoked_at') === null
            || (($state === 'enrolling') !== DB::table('auth_recovery_gates')->where('case_id', $case->id)->whereNull('released_at')->exists())) {
            throw new RuntimeException('Revocation/finalize serial order violated');
        }
        echo "B3 registry revoke vs finalize: two blocked PIDs, serialized commit order PASS\n";
    } else {
        echo "B3 $mode: two blocked PIDs, one terminal winner, no partial grant/factor PASS\n";
    }
}
