<?php

use App\Mail\EmailOtp;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ControlledAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $exception) {
    fwrite(STDERR, 'AUTH4 concurrency invariant failed (details suppressed)'.PHP_EOL);
    exit(1);
});
config(['auth4.enabled' => true, 'auth4.key_version' => 'test_v1', 'auth4.keys.test_v1' => 'synthetic-concurrency-hmac-key-32minimum',
    'auth4.rate_key' => 'synthetic-concurrency-rate-key-32minimum', 'mfa.enabled' => true, 'session.driver' => 'database',
    'cache.default' => 'database', 'mail.default' => 'array', 'queue.default' => 'sync', 'mfa.flow_seconds' => 300,
    'mfa.session_seconds' => 28800, 'mfa.attempts' => 10, 'mfa.window' => 1, 'mfa.recovery_count' => 3, 'mfa.key_version' => 'test_v1']);
$auth = app(ClientAuth::class);
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name = 'auth4_race_worker'");
    try {
        if ($input['action'] === 'request') {
            $auth->request('register', $input['email'], $input['flow'], $input['binding'], $input['ip']);
        } elseif ($input['action'] === 'verify') {
            $auth->verify($input['challenge'], $input['code'], $input['binding'], $input['ip']);
        } else {
            $auth->complete($input['flow'], 'Synthetic race', $input['email'], $input['binding'], $input['ip']);
        }
        echo 'accepted';
    } catch (AuthDenied) {
        echo 'denied';
    } catch (Throwable) {
        echo 'error';
        exit(2);
    }
    exit;
}

function race(array $inputs, array $expected = ['accepted', 'denied']): void
{
    DB::beginTransaction();
    DB::select('SELECT pg_advisory_xact_lock(4040404)');
    $workers = [];
    foreach ($inputs as $input) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $waiting = false;
    for ($n = 0; $n < 40; $n++) {
        DB::select('SELECT pg_stat_clear_snapshot()');
        if (DB::selectOne("SELECT count(DISTINCT pid) AS n FROM pg_stat_activity WHERE application_name='auth4_race_worker' AND wait_event_type='Lock'")->n === 2) {
            $waiting = true;
            break;
        }
        usleep(50000);
    }
    DB::commit();
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') {
            throw new RuntimeException('Worker error');
        }
    }
    sort($results);
    if (! $waiting || $results !== $expected) {
        throw new RuntimeException('Expected two blocked PG PIDs and one winner');
    }
}

DB::table('users')->insert(['id' => 8410000000, 'name' => 'AUTH4 race coach', 'role' => 'admin']);
Mail::fake();
function prepared(string $email, string $binding, string $ip, ?string $token = null): array
{
    $auth = app(ClientAuth::class);
    if ($token === null) {
        $token = bin2hex(random_bytes(32));
        DB::table('client_invitations')->insert(['token_hash' => hash('sha256', $token), 'created_by_user_id' => 8410000000, 'coach_user_id' => 8410000000]);
    }
    $flow = $auth->exchange($token, $binding, $ip)['flow_id'];
    $challenge = $auth->request('register', $email, $flow, $binding, $ip)['challenge_id'];
    preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);

    return ['flow' => $flow, 'challenge' => $challenge, 'code' => $match[1], 'email' => $email, 'binding' => $binding, 'ip' => $ip, 'token' => $token];
}

$input = prepared('auth4-race-otp@example.invalid', bin2hex(random_bytes(32)), '192.0.2.11');
race([array_merge($input, ['action' => 'verify']), array_merge($input, ['action' => 'verify'])]);
echo "AUTH4 OTP consume: two blocked PG PIDs, exactly one service winner PASS\n";
race([array_merge($input, ['action' => 'complete']), array_merge($input, ['action' => 'complete'])]);
if (DB::table('users')->where('email_normalized', $input['email'])->count() !== 1) {
    throw new RuntimeException('Duplicate user');
}
echo "AUTH4 same invitation/flow complete: two blocked PG PIDs, one user/client/consume PASS\n";

$left = prepared('auth4-left@example.invalid', bin2hex(random_bytes(32)), '192.0.2.12');
$right = prepared('auth4-right@example.invalid', bin2hex(random_bytes(32)), '192.0.2.13', $left['token']);
foreach ([$left, $right] as $i) {
    $auth->verify($i['challenge'], $i['code'], $i['binding'], $i['ip']);
}
race([array_merge($left, ['action' => 'complete']), array_merge($right, ['action' => 'complete'])]);
if (DB::table('users')->whereIn('email_normalized', [$left['email'], $right['email']])->count() !== 1) {
    throw new RuntimeException('Duplicate invitation registration');
}
echo "AUTH4 same invitation/different flows: exactly one registration PASS\n";

$left = prepared('auth4-same@example.invalid', bin2hex(random_bytes(32)), '192.0.2.14');
// Synthetic passage of cooldown only; account day/quarter-hour budgets remain.
$key = 'auth4:'.app(ControlledAuth::class)->fingerprint('cooldown:register:auth4-same@example.invalid');
Cache::store('database')->forget($key);
$right = prepared('AUTH4-SAME@example.invalid', bin2hex(random_bytes(32)), '192.0.2.15');
foreach ([$left, $right] as $i) {
    $auth->verify($i['challenge'], $i['code'], $i['binding'], $i['ip']);
}
race([array_merge($left, ['action' => 'complete']), array_merge($right, ['action' => 'complete'])]);
if (DB::table('users')->where('email_normalized', 'auth4-same@example.invalid')->count() !== 1
    || DB::table('client_invitations')->whereIn('token_hash', [hash('sha256', $left['token']), hash('sha256', $right['token'])])->whereNull('consumed_at')->count() !== 1) {
    throw new RuntimeException('Email conflict consumed both invitations');
}
echo "AUTH4 two invitations/same normalized email: one user, loser invitation unconsumed PASS\n";

$inputs = [];
foreach (['192.0.2.21', '192.0.2.22'] as $ip) {
    $token = bin2hex(random_bytes(32));
    DB::table('client_invitations')->insert(['token_hash' => hash('sha256', $token), 'created_by_user_id' => 8410000000, 'coach_user_id' => 8410000000]);
    $binding = bin2hex(random_bytes(32));
    $inputs[] = ['action' => 'request', 'email' => 'auth4-issue-race@example.invalid', 'binding' => $binding, 'ip' => $ip, 'flow' => $auth->exchange($token, $binding, $ip)['flow_id']];
}
race($inputs, ['accepted', 'accepted']);
if (DB::table('auth_challenges')->where('email_normalized', 'auth4-issue-race@example.invalid')->count() !== 1) {
    throw new RuntimeException('Issuance bypassed shared cooldown');
}
echo "AUTH4 concurrent issuance/different flows and IPs: two neutral responses, exactly one delivery PASS\n";
