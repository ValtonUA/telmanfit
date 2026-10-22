<?php

use App\Mail\EmailOtp;
use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\BudgetUnavailable;
use App\Services\Auth\Budgets\PostgresBudgets;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use App\Support\Auth\AuthSecretCodec;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/auth2-bootstrap.php';
config(['auth4.rate_key' => 'synthetic-only-stable-rate-key-minimum32', 'auth4.enabled' => true,
    'auth4.key_version' => 'test_v1', 'auth4.keys.test_v1' => 'synthetic-only-otp-key-32-minimum-bytes',
    'mfa.enabled' => true, 'mfa.flow_seconds' => 300, 'mfa.session_seconds' => 28800,
    'mfa.attempts' => 100, 'mfa.window' => 1, 'mfa.recovery_count' => 3, 'mfa.key_version' => 'test_v1',
    'session.driver' => 'database', 'cache.default' => 'database', 'queue.default' => 'sync', 'mail.default' => 'array']);
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'B6 invariant failed: '.get_class($e).' line '.$e->getLine()."\n");
    exit(1);
});
function b6keys(array $input): array
{
    return array_map(fn ($row) => new BudgetKey(BudgetScope::from($row[0]), $row[1]), $input);
}
function b6raw(array $keys, string $mode = 'consume'): object
{
    return DB::selectOne('SELECT * FROM auth6b6_group(?::text[],?::text[],?)',
        ['{'.implode(',', array_map(fn ($k) => $k->scope->value, $keys)).'}', '{'.implode(',', array_column($keys, 'hash')).'}', $mode]);
}
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement('SELECT set_config(?,?,false)', ['application_name', $input['name']]);
    $property = new ReflectionProperty(DB::connection(), 'config');
    $property->setValue(DB::connection(), array_replace(DB::connection()->getConfig(), ['application_name' => $input['name']]));
    echo 'ready:'.DB::selectOne('SELECT pg_backend_pid() pid')->pid."\n";
    fflush(STDOUT);
    fgets(STDIN); // Явний orchestrator barrier, без timing-only start.
    $keys = b6keys($input['keys'] ?? []);
    if (in_array($input['action'], ['otp', 'totp', 'recovery-code', 'otp-fail', 'totp-fail'], true)) {
        $c = $input['context'];
        try {
            if (str_starts_with($input['action'], 'otp')) {
                app(ClientAuth::class)->verify($c['challenge'], $c['code'], $c['binding'], $c['ip']);
            } else {
                app(MfaService::class)->finishLogin($c['flow'], $c['binding'], $c['code'], $input['action'] === 'recovery-code');
            }
            echo "success\n";
        } catch (AuthDenied|MfaDenied) {
            echo "denied\n";
        }
        exit;
    }
    if ($input['action'] === 'raw-crash') {
        DB::beginTransaction();
        b6raw($keys);
        echo "mutated\n";
        fflush(STDOUT);
        if (trim(fgets(STDIN)) === 'commit') {
            DB::commit();
            echo "committed\n";
            fflush(STDOUT);
            fgets(STDIN);
        }
        exit;
    }
    if ($input['action'] === 'cleanup') {
        echo json_encode(['deleted' => app(PostgresBudgets::class)->cleanup()])."\n";
        exit;
    }
    if ($input['action'] === 'deadlock') {
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout='5s'");
        DB::select('SELECT 1 FROM auth_rate_budgets WHERE scope=? AND key_hash=? FOR UPDATE', [$keys[1]->scope->value, $keys[1]->hash]);
        echo "first-locked\n";
        fflush(STDOUT);
        fgets(STDIN);
        try {
            b6raw([$keys[0]]);
            DB::commit();
            echo "survived\n";
        } catch (QueryException $e) {
            DB::rollBack();
            echo $e->errorInfo[0]."\n";
        }
        exit;
    }
    $times = $lockTimes = [];
    $allowed = $denied = $errors = 0;
    for ($i = 0; $i < ($input['count'] ?? 1); $i++) {
        $start = hrtime(true);
        try {
            $decision = app(PostgresBudgets::class)->admit($keys);
            $decision->allowed ? $allowed++ : $denied++;
            $lockTimes[] = $decision->lockMilliseconds;
        } catch (BudgetUnavailable) {
            $errors++;
        }
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    echo json_encode(['allowed' => $allowed, 'denied' => $denied, 'errors' => $errors, 'ms' => $times, 'lock_ms' => $lockTimes,
        // Більше за типовий pipe buffer: orchestrator мусить drain-ити live output.
        'pipe_padding' => ($input['count'] ?? 1) > 1 ? str_repeat('x', 65536) : '',
        'rss_kb' => getrusage()['ru_maxrss'], 'cpu_user_seconds' => getrusage()['ru_utime.tv_sec'] + getrusage()['ru_utime.tv_usec'] / 1e6])."\n";
    exit;
}
function b6spawn(string $action, array $keys, int $count = 1, array $context = []): array
{
    static $sequence = 0;
    $name = 'b6_worker_'.++$sequence;
    $p = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(compact('name', 'action', 'keys', 'count', 'context'))."\n");
    fflush($pipes[0]);
    if (! str_starts_with(fgets($pipes[1]), 'ready:')) {
        throw new RuntimeException('worker_not_ready');
    }

    return [$p, $pipes, $name];
}
function b6go(array $worker, string $command = 'go'): void
{
    fwrite($worker[1][0], $command."\n");
    fflush($worker[1][0]);
}
function b6finish(array $worker, bool $killed = false): string
{
    [$p, $pipes] = $worker;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($p);
    if ((! $killed && $exit !== 0) || $err !== '') {
        throw new RuntimeException('worker_failed');
    }

    return trim($out);
}
function b6wait(array $names, int $required): void
{
    for ($i = 0; $i < 150; $i++) {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $n = DB::table('pg_stat_activity')->whereIn('application_name', $names)->where('wait_event_type', 'Lock')->count();
        if ($n >= $required) {
            return;
        }
        usleep(10000);
    }
    throw new RuntimeException('lock_wait_not_observed');
}
function b6count(BudgetKey $key): int
{
    return (int) (DB::selectOne('SELECT cardinality(events) n FROM auth_rate_budgets WHERE scope=? AND key_hash=?', [$key->scope->value, $key->hash])->n ?? 0);
}
$results = [];
// L-1, absent-key UNIQUE conflict, overlapping multi-key groups; both commit/rollback.
foreach (['existing', 'absent', 'group'] as $scenario) {
    foreach ([false, true] as $rollback) {
        $raw = [['otp_email_short', bin2hex(random_bytes(16))]];
        if ($scenario === 'group') {
            $raw[] = ['otp_email_all', bin2hex(random_bytes(16))];
        }
        $keys = b6keys($raw);
        $seed = $scenario === 'absent' ? 0 : 2;
        for ($i = 0; $i < $seed; $i++) {
            app(PostgresBudgets::class)->admit($keys);
        }
        DB::beginTransaction();
        b6raw($keys);
        $a = b6spawn('consume', $raw);
        $b = b6spawn('consume', array_reverse($raw));
        b6go($a);
        b6go($b);
        b6wait([$a[2], $b[2]], 2);
        $rollback ? DB::rollBack() : DB::commit();
        $out = [json_decode(b6finish($a), true), json_decode(b6finish($b), true)];
        $allowed = array_sum(array_column($out, 'allowed'));
        $expected = min(2, 3 - $seed - ($rollback ? 0 : 1));
        if ($allowed !== $expected || array_sum(array_column($out, 'errors')) !== 0 || b6count($keys[0]) !== $seed + ($rollback ? 0 : 1) + $allowed
            || ($scenario === 'group' && b6count($keys[0]) !== b6count($keys[1]))) {
            throw new RuntimeException('race_quota_bypass');
        }
        $results[] = ['scenario' => $scenario, 'rollback' => $rollback, 'independent_workers' => 2, 'observed_lock_waits' => 2, 'allowed' => $allowed];
    }
}
echo "B6 independent PG workers: boundary/absent/group commit+rollback PASS; deterministic barriers and observed waits\n";
// Lock timeout adapter redaction and no append.
$raw = [['otp_cooldown', bin2hex(random_bytes(16))]];
$key = b6keys($raw)[0];
DB::beginTransaction();
b6raw([$key]);
$worker = b6spawn('consume', $raw);
b6go($worker);
b6wait([$worker[2]], 1);
$out = json_decode(b6finish($worker), true);
DB::rollBack();
if ($out['errors'] !== 1 || $out['allowed'] !== 0 || b6count($key) !== 0) {
    throw new RuntimeException('timeout_open');
}
$results[] = ['scenario' => 'lock_timeout', 'observed_lock_waits' => 1, 'closed' => true];
// Справжній deadlock від стороннього порушника порядку locks; одна transaction abort.
$raw = [['otp_cooldown', bin2hex(random_bytes(16))], ['otp_cooldown', bin2hex(random_bytes(16))]];
$keys = b6keys($raw);
foreach ($keys as $key) {
    app(PostgresBudgets::class)->admit([$key]);
}
DB::beginTransaction();
DB::select('SELECT 1 FROM auth_rate_budgets WHERE scope=? AND key_hash=? FOR UPDATE', [$keys[0]->scope->value, $keys[0]->hash]);
$worker = b6spawn('deadlock', $raw);
b6go($worker);
if (trim(fgets($worker[1][1])) !== 'first-locked') {
    throw new RuntimeException('deadlock_barrier');
}
b6go($worker);
b6wait([$worker[2]], 1);
$parentAborted = false;
try {
    DB::select('SELECT 1 FROM auth_rate_budgets WHERE scope=? AND key_hash=? FOR UPDATE', [$keys[1]->scope->value, $keys[1]->hash]);
    DB::commit();
} catch (QueryException $e) {
    $parentAborted = $e->errorInfo[0] === '40P01';
    DB::rollBack();
}
$child = b6finish($worker);
if (! $parentAborted && $child !== '40P01') {
    throw new RuntimeException('deadlock_not_detected');
}
$results[] = ['scenario' => 'real_pg_deadlock', 'sqlstate' => '40P01', 'observed_lock_waits' => 1, 'no_new_admission' => true];
// Process death before/after real PostgreSQL COMMIT; lost response never refunds.
foreach ([false, true] as $commit) {
    $raw = [['otp_cooldown', bin2hex(random_bytes(16))]];
    $key = b6keys($raw)[0];
    $worker = b6spawn('raw-crash', $raw);
    b6go($worker);
    if (trim(fgets($worker[1][1])) !== 'mutated') {
        throw new RuntimeException('crash_barrier');
    }
    if ($commit) {
        b6go($worker, 'commit');
        if (trim(fgets($worker[1][1])) !== 'committed') {
            throw new RuntimeException('commit_barrier');
        }
    }
    proc_terminate($worker[0], 9);
    b6finish($worker, true);
    if (app(PostgresBudgets::class)->admit([$key])->allowed === $commit) {
        throw new RuntimeException('crash_refunded');
    }
    $results[] = ['scenario' => $commit ? 'crash_after_commit_response_lost' : 'crash_before_commit', 'pass' => true];
}
// Expired candidate is locked by consume: cleanup SKIP LOCKED cannot free a live window.
$raw = [['otp_cooldown', bin2hex(random_bytes(16))]];
$key = b6keys($raw)[0];
DB::statement("INSERT INTO auth_rate_budgets(scope,key_hash,events,last_clock_at,gc_after) VALUES(?,?,'{}',clock_timestamp()-interval '2 hours',clock_timestamp()-interval '1 hour')", [$key->scope->value, $key->hash]);
DB::beginTransaction();
b6raw([$key]);
$worker = b6spawn('cleanup', []);
b6go($worker);
b6finish($worker);
DB::commit();
if (app(PostgresBudgets::class)->admit([$key])->allowed) {
    throw new RuntimeException('cleanup_freed_attempt');
}
$results[] = ['scenario' => 'cleanup_consume', 'pass' => true];
// Справжні domain OTP/TOTP/recovery-code races; parent user lock задає barrier.
foreach (['otp', 'totp', 'recovery-code', 'otp-fail', 'totp-fail'] as $method) {
    $email = bin2hex(random_bytes(12)).'@example.invalid';
    $coach = DB::table('users')->insertGetId(['name' => 'Synthetic B6 coach', 'role' => 'admin']);
    $user = DB::table('users')->insertGetId(['name' => 'Synthetic B6 client', 'role' => 'client', 'email' => $email,
        'email_normalized' => $email, 'email_verified_at' => DB::raw('clock_timestamp()')]);
    DB::table('clients')->insert(['user_id' => $user, 'coach_user_id' => $coach]);
    $binding = bin2hex(random_bytes(32));
    $ip = 'synthetic:'.bin2hex(random_bytes(16));
    if (str_starts_with($method, 'otp')) {
        Mail::fake();
        $response = app(ClientAuth::class)->request('login', $email, null, $binding, $ip);
        $mail = Mail::sent(EmailOtp::class)->last();
        preg_match('/<strong>([0-9]{8})<\/strong>/', $mail->render(), $match);
        $context = ['challenge' => $response['challenge_id'], 'code' => $match[1], 'binding' => $binding, 'ip' => $ip];
    } else {
        $flow = (string) Str::uuid();
        DB::table('auth_flows')->insert(['public_id' => $flow, 'user_id' => $user, 'auth_version_at_start' => 0,
            'browser_binding_hash' => hash('sha256', $binding), 'audience' => 'client', 'purpose' => 'login', 'state' => 'primary_verified',
            'primary_verified_at' => DB::raw('clock_timestamp()'), 'expires_at' => DB::raw("clock_timestamp()+interval '5 minutes'")]);
        $secret = 'JBSWY3DPEHPK3PXP';
        $batch = (string) Str::uuid();
        DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret),
            'key_version' => 'test_v1', 'confirmed_at' => DB::raw('clock_timestamp()'), 'recovery_batch_id' => $batch]);
        $code = (new Google2FA)->getCurrentOtp($secret);
        if ($method === 'recovery-code') {
            $selector = (string) Str::uuid();
            $rawCode = bin2hex(random_bytes(20));
            DB::table('mfa_recovery_codes')->insert(['user_id' => $user, 'selector' => $selector, 'batch_id' => $batch,
                'code_hash' => app(AuthSecretCodec::class)->hashRecoveryCode($rawCode)]);
            $code = $selector.'.'.$rawCode;
        }
        $context = compact('flow', 'code', 'binding', 'ip');
    }
    $failureCase = str_ends_with($method, '-fail');
    if ($failureCase) {
        $context['code'] = 'wrong';
        for ($seed = 0; $seed < 4; $seed++) {
            try {
                if (str_starts_with($method, 'otp')) {
                    app(ClientAuth::class)->verify($context['challenge'], 'wrong', $binding, $ip);
                } else {
                    app(MfaService::class)->finishLogin($flow, $binding, 'wrong');
                }
            } catch (AuthDenied|MfaDenied) {
            }
        }
    }
    DB::beginTransaction();
    DB::table('users')->where('id', $user)->lockForUpdate()->first();
    $a = b6spawn($method, [], context: $context);
    $b = b6spawn($method, [], context: $context);
    b6go($a);
    b6go($b);
    b6wait([$a[2], $b[2]], 2);
    DB::commit();
    $out = [b6finish($a), b6finish($b)];
    sort($out);
    if ($out !== ($failureCase ? ['denied', 'denied'] : ['denied', 'success'])) {
        throw new RuntimeException('domain_double_consume');
    }
    if ($failureCase) {
        $failureKey = str_starts_with($method, 'otp') ? new BudgetKey(BudgetScope::OtpFailure, $email) : new BudgetKey(BudgetScope::MfaFlow, $flow);
        if (b6count($failureKey) !== 5 || (str_starts_with($method, 'otp') && DB::table('auth_challenges')->where('public_id', $context['challenge'])->value('attempts') !== 5)) {
            throw new RuntimeException('failure_not_durable_or_overcounted');
        }
        $audit = DB::table('auth_audit_events')->where('subject_user_id', $user)->where('event_type', str_starts_with($method, 'otp') ? 'client.otp_denied' : 'mfa.denied')->count();
        if ($audit !== 5) {
            throw new RuntimeException('failure_audit_not_atomic');
        }
    }
    $results[] = ['scenario' => $method.'_same_code', 'observed_lock_waits' => 2, 'success' => $failureCase ? 0 : 1, 'denied' => $failureCase ? 2 : 1];
}
echo "B6 OTP/TOTP/recovery-code independent domain races PASS: one consume, one denial, observed user/global waits\n";
// Synthetic benchmark, one real shared global key, 4 workers ×300, expected1000/200.
$raw = [['otp_global', 'benchmark:'.bin2hex(random_bytes(16))]];
$workers = [];
for ($i = 0; $i < 4; $i++) {
    $workers[] = b6spawn('consume', $raw, 300);
}
$start = hrtime(true);
$walBefore = DB::selectOne('SELECT wal_bytes::numeric n FROM pg_stat_wal')->n;
$deadBefore = DB::selectOne('SELECT deadlocks FROM pg_stat_database WHERE datname=current_database()')->deadlocks;
foreach ($workers as $worker) {
    b6go($worker);
}
$maxConnections = $lockSamples = $samples = 0;
$buffers = array_fill(0, count($workers), '');
foreach ($workers as $worker) {
    stream_set_blocking($worker[1][1], false);
}
$deadline = microtime(true) + 120;
do {
    $running = false;
    foreach ($workers as $index => $worker) {
        $buffers[$index] .= stream_get_contents($worker[1][1]);
        $running = proc_get_status($worker[0])['running'] || $running;
    }
    if (microtime(true) > $deadline) {
        foreach ($workers as $worker) {
            proc_terminate($worker[0], 9);
        }
        throw new RuntimeException('benchmark_deadline_exceeded');
    }
    DB::select('SELECT pg_stat_clear_snapshot()');
    $activity = DB::selectOne("SELECT count(*) n,count(*) FILTER(WHERE wait_event_type='Lock') waits FROM pg_stat_activity WHERE datname=current_database()");
    $maxConnections = max($maxConnections, $activity->n);
    $lockSamples += $activity->waits;
    $samples++;
    usleep(10000);
} while ($running);
$duration = (hrtime(true) - $start) / 1e9;
$out = [];
foreach ($workers as $index => $worker) {
    stream_set_blocking($worker[1][1], true);
    // proc_get_status може забрати exit status; pipe payload є окремим доказом.
    $out[] = json_decode($buffers[$index].b6finish($worker, true), true, flags: JSON_THROW_ON_ERROR);
}
$times = array_merge(...array_column($out, 'ms'));
$lockTimes = array_merge(...array_column($out, 'lock_ms'));
sort($lockTimes);
sort($times);
if (array_sum(array_column($out, 'allowed')) !== 1000 || array_sum(array_column($out, 'denied')) !== 200 || array_sum(array_column($out, 'errors')) !== 0) {
    throw new RuntimeException('global_cap_bypass');
}
DB::select('SELECT pg_stat_clear_snapshot()');
$benchmark = ['synthetic_only' => true, 'php' => PHP_VERSION, 'pg_major' => 18, 'workers' => 4, 'requests' => 1200,
    'allowed' => 1000, 'denied' => 200, 'errors_503' => array_sum(array_column($out, 'errors')), 'seconds' => $duration,
    'requests_per_second' => 1200 / $duration, 'latency_p95_ms' => $times[(int) floor(count($times) * .95)],
    'latency_p99_ms' => $times[(int) floor(count($times) * .99)], 'lock_acquisition_p95_ms' => $lockTimes[(int) floor(count($lockTimes) * .95)],
    'lock_acquisition_p99_ms' => $lockTimes[(int) floor(count($lockTimes) * .99)], 'max_pg_connections_sampled' => $maxConnections,
    'lock_wait_samples' => $lockSamples, 'activity_samples_10ms' => $samples,
    'worker_max_rss_kb' => max(array_column($out, 'rss_kb')), 'worker_cpu_user_seconds' => array_sum(array_column($out, 'cpu_user_seconds')),
    'wal_bytes_delta' => (float) DB::selectOne('SELECT wal_bytes::numeric n FROM pg_stat_wal')->n - (float) $walBefore,
    'budgets_total_bytes' => DB::selectOne("SELECT pg_total_relation_size('auth_rate_budgets') n")->n,
    'deadlocks_delta' => DB::selectOne('SELECT deadlocks FROM pg_stat_database WHERE datname=current_database()')->deadlocks - $deadBefore,
    'io' => DB::selectOne('SELECT sum(reads) reads,sum(writes) writes,sum(extends) extends FROM pg_stat_io'),
    'production_peak_approved' => false, 'd8_acceptance' => false];
file_put_contents(base_path('docs/evidence/auth6b-b6/concurrency.json'), json_encode($results, JSON_PRETTY_PRINT)."\n");
file_put_contents(base_path('docs/evidence/auth6b-b6/benchmark.json'), json_encode($benchmark, JSON_PRETTY_PRINT)."\n");
chmod(base_path('docs/evidence/auth6b-b6/concurrency.json'), 0644);
chmod(base_path('docs/evidence/auth6b-b6/benchmark.json'), 0644);
echo "B6 crashes/timeout/cleanup PASS; 4 workers shared global1000/hour: 1000 allowed,200 denied,0 errors\n";
