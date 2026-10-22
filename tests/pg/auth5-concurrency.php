<?php

use App\Mail\EmailOtp;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSecurity;
use App\Services\Auth\Client\ClientSession;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use App\Support\Auth\AuthSecretCodec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'AUTH5 concurrency invariant failed: '.get_class($error).' line '.$error->getLine()." (details suppressed)\n");
    exit(1);
});
config(['auth4.enabled' => true, 'auth5.enabled' => true, 'auth4.key_version' => 'test_v1',
    'auth4.keys.test_v1' => 'synthetic-concurrency-hmac-key-32minimum', 'auth4.rate_key' => 'synthetic-concurrency-rate-key-32minimum',
    'mfa.enabled' => true, 'session.driver' => 'database', 'cache.default' => 'database', 'mail.default' => 'array',
    'queue.default' => 'sync', 'mfa.flow_seconds' => 300, 'mfa.session_seconds' => 28800, 'mfa.attempts' => 10,
    'mfa.window' => 1, 'mfa.recovery_count' => 3, 'mfa.key_version' => 'test_v1']);

function raceRequest(?string $id = null): Request
{
    $request = Request::create('/api/auth/security/sessions', 'POST', server: ['REMOTE_ADDR' => '192.0.2.85']);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->setName('telmanfit_client_session');
    if ($id) {
        $request->session()->setId($id);
    }
    $request->session()->start();
    app()->instance('request', $request);

    return $request;
}

if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name = 'auth5_race_worker'");
    $request = raceRequest($input['session']);
    try {
        $service = app(ClientSecurity::class);
        if ($input['action'] === 'enroll') {
            $service->enrollment($request, $input['proof'], false);
        } elseif ($input['action'] === 'logout') {
            $service->logoutAll($request, $input['proof']);
        } elseif ($input['action'] === 'disable') {
            $service->disable($request, $input['proof']);
        } elseif ($input['action'] === 'confirm') {
            $service->confirmEnrollment($request, $input['flow'], $input['code']);
        } else {
            $service->confirmEmail($request, $input['challenge'], $input['code']);
        }
        $request->session()->save();
        echo 'accepted';
    } catch (AuthDenied|MfaDenied) {
        echo 'denied';
    } catch (Throwable) {
        echo 'error';
        exit(2);
    }
    exit;
}

function race(array $input): void
{
    DB::beginTransaction();
    DB::select('SELECT pg_advisory_xact_lock(4040404)');
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $waiting = false;
    for ($i = 0; $i < 50; $i++) {
        DB::select('SELECT pg_stat_clear_snapshot()');
        if (DB::selectOne("SELECT count(DISTINCT pid) AS n FROM pg_stat_activity WHERE application_name='auth5_race_worker' AND wait_event_type='Lock'")->n === 2) {
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
            throw new RuntimeException('Worker failure');
        }
    }
    sort($results);
    if (! $waiting || $results !== ['accepted', 'denied']) {
        throw new RuntimeException('Expected two PG lock waits and exactly one service winner');
    }
}

Mail::fake();
DB::table('users')->insert(['id' => 8510000000, 'name' => 'AUTH5 race coach', 'role' => 'admin']);
function otp(): string
{
    preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);

    return $match[1];
}
function prepared(string $action, string $suffix, bool $active = false): array
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    $email = 'auth5-race-'.$suffix.'@example.invalid';
    $id = DB::table('users')->insertGetId(['name' => 'AUTH5 race', 'email' => $email, 'email_normalized' => $email, 'email_verified_at' => now()]);
    DB::table('clients')->insert(['user_id' => $id, 'coach_user_id' => 8510000000]);
    $secret = null;
    if ($active) {
        $secret = (new Google2FA)->generateSecretKey(32);
        DB::table('user_totp_factors')->insert(['user_id' => $id, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret),
            'key_version' => 'test_v1', 'created_at' => now()->subMinute(), 'confirmed_at' => now()]);
    }
    $request = raceRequest();
    $binding = bin2hex(random_bytes(32));
    $request->session()->put('client_binding', $binding);
    $auth = app(ClientAuth::class);
    $result = $auth->request('login', $email, null, $binding, '192.0.2.85');
    $result = $auth->verify($result['challenge_id'], otp(), $binding, '192.0.2.85');
    if ($active) {
        if (time() % 30 >= 26) {
            usleep((31 - time() % 30) * 1000000);
        }
        $proof = app(MfaService::class)->finishLogin($result['flow_id'], $binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30) - 1));
    } else {
        $proof = $result['proof'];
    }
    app(ClientSession::class)->issue($request, $proof);
    $service = app(ClientSecurity::class);
    $result = $service->requestReauth($request, $action);
    $result = $service->verifyReauth($request, $result['challenge_id'], otp());
    if ($active) {
        $result = $service->authorize($request, $result['flow_id'], (new Google2FA)->oathTotp($secret, intdiv(time(), 30)), false);
    }
    $request->session()->save();

    return ['session' => $request->session()->getId(), 'proof' => $result['flow_id'], 'request' => $request, 'user' => $id];
}

$input = prepared('enable_factor', 'enroll');
race(['action' => 'enroll', 'session' => $input['session'], 'proof' => $input['proof']]);
if (DB::table('user_totp_factors')->where('user_id', $input['user'])->count() !== 1) {
    throw new RuntimeException('Duplicate factor');
}
echo "AUTH5 action proof / enrollment: two blocked PG PIDs, exactly one service winner PASS\n";
$input = prepared('logout_all', 'logout');
race(['action' => 'logout', 'session' => $input['session'], 'proof' => $input['proof']]);
if (DB::table('users')->where('id', $input['user'])->value('auth_version') !== 1) {
    throw new RuntimeException('Duplicate revoke');
}
echo "AUTH5 action proof / logout-all: exactly one version bump PASS\n";
$input = prepared('email_change', 'email');
$result = app(ClientSecurity::class)->requestEmail($input['request'], $input['proof'], 'auth5-race-changed@example.invalid');
$input['request']->session()->save();
race(['action' => 'email', 'session' => $input['session'], 'challenge' => $result['challenge_id'], 'code' => otp()]);
if (DB::table('users')->where('id', $input['user'])->value('email_normalized') !== 'auth5-race-changed@example.invalid'
    || DB::table('auth_audit_events')->where('subject_user_id', $input['user'])->where('event_type', 'client.email_changed')->count() !== 1) {
    throw new RuntimeException('Email commit invariant');
}
echo "AUTH5 new email OTP: exactly one confirmation, identity commit and audit PASS\n";

$input = prepared('disable_factor', 'disable', true);
$watermark = DB::table('user_totp_factors')->where('user_id', $input['user'])->value('last_used_step');
race(['action' => 'disable', 'session' => $input['session'], 'proof' => $input['proof']]);
$factor = DB::table('user_totp_factors')->where('user_id', $input['user'])->first();
if ($factor->disabled_at === null || $factor->secret_ciphertext !== null || $factor->last_used_step !== $watermark
    || DB::table('users')->where('id', $input['user'])->value('auth_version') !== 1) {
    throw new RuntimeException('Disable history invariant');
}
echo "AUTH5 disable: exactly one version bump, ciphertext cleared, watermark preserved PASS\n";
$request = $input['request'];
$email = 'auth5-race-disable@example.invalid';
Cache::store('database')->forget('auth4:'.app(ControlledAuth::class)->fingerprint('verify:ip:192.0.2.85'));
foreach (['login', 'reauth'] as $purpose) {
    Cache::store('database')->forget('auth4:'.app(ControlledAuth::class)->fingerprint('cooldown:'.$purpose.':'.$email));
}
$binding = $request->session()->get('client_binding');
$result = app(ClientAuth::class)->request('login', $email, null, $binding, '192.0.2.85');
$result = app(ClientAuth::class)->verify($result['challenge_id'], otp(), $binding, '192.0.2.85');
app(ClientSession::class)->issue($request, $result['proof']);
$service = app(ClientSecurity::class);
$result = $service->requestReauth($request, 'enable_factor');
$result = $service->verifyReauth($request, $result['challenge_id'], otp());
$enrollment = $service->enrollment($request, $result['flow_id'], false);
$request->session()->save();
$step = max(intdiv(time(), 30), $watermark + 1);
$code = (new Google2FA)->oathTotp($enrollment['secret']->reveal(), $step);
race(['action' => 'confirm', 'session' => $request->session()->getId(), 'flow' => $enrollment['flow_id'], 'code' => $code]);
$factor = DB::table('user_totp_factors')->where('user_id', $input['user'])->first();
if (! MfaService::active($factor) || $factor->last_used_step <= $watermark
    || DB::table('auth_audit_events')->where('subject_user_id', $input['user'])->where('event_type', 'mfa.factor_activated')->count() !== 1) {
    throw new RuntimeException('Reenrollment invariant');
}
echo "AUTH5 reenrollment: exactly one new factor activation, watermark advanced PASS\n";
