<?php

use App\Http\Middleware\RequireAdminMfa;
use App\Services\Auth\AdminAssurance;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $exception) {
    fwrite(STDERR, 'AUTH3 concurrency invariant failed (details suppressed)'.PHP_EOL);
    exit(1);
});
config(['mfa.enabled' => true, 'session.driver' => 'database', 'mfa.flow_seconds' => 600,
    'mfa.session_seconds' => 3600, 'mfa.attempts' => 100, 'mfa.window' => 1,
    'mfa.recovery_count' => 3, 'mfa.key_version' => 'test_v1']);
$mfa = app(MfaService::class);
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name = '".(($input['action'] ?? '') === 'logout_all' ? 'auth3_gate_revoker' : 'auth3_race_worker')."'");
    try {
        if (($input['action'] ?? '') === 'logout_all') {
            $mfa->logoutAll($input['flow'], $input['binding'], $input['code'], true);
        } else {
            $mfa->finishLogin($input['flow'], $input['binding'], $input['code'], $input['recovery']);
        }
        echo 'accepted';
    } catch (MfaDenied) {
        echo 'denied';
    } catch (Throwable) {
        echo 'error';
        exit(2);
    }
    exit;
}

DB::table('users')->insert(['id' => 8310000000, 'name' => 'AUTH3 Race', 'role' => 'admin',
    'email' => 'auth3-race@example.invalid', 'password' => Hash::make('synthetic-race')]);
$bindings = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
$flow = $mfa->beginPasswordLogin('auth3-race@example.invalid', 'synthetic-race', $bindings[0]);
$secret = $mfa->beginEnrollment($flow, $bindings[0])->reveal();
$totp = new Google2FA;
if (time() % 30 >= 26) {
    usleep((31 - time() % 30) * 1000000);
}
$codes = $mfa->confirmEnrollment($flow, $bindings[0], $totp->oathTotp($secret, intdiv(time(), 30) - 1))->reveal();
foreach ([false, true] as $recovery) {
    $flows = [];
    foreach ($bindings as $binding) {
        $flows[] = $mfa->beginPasswordLogin('auth3-race@example.invalid', 'synthetic-race', $binding);
    }
    $code = $recovery ? $codes[0] : $totp->oathTotp($secret, intdiv(time(), 30));
    DB::beginTransaction();
    DB::table('users')->where('id', 8310000000)->lockForUpdate()->first();
    $workers = [];
    foreach ($flows as $i => $flowId) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['flow' => $flowId, 'binding' => $bindings[$i], 'code' => $code, 'recovery' => $recovery]));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $waiting = false;
    for ($n = 0; $n < 150; $n++) {
        $count = DB::selectOne("SELECT count(DISTINCT pid) AS n FROM pg_stat_activity WHERE application_name='auth3_race_worker' AND wait_event_type='Lock'")->n;
        if ($count === 2) {
            $waiting = true;
            break;
        }
        usleep(100000);
        DB::select('SELECT pg_stat_clear_snapshot()');
    }
    DB::commit();
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new RuntimeException('AUTH3 worker failed (details suppressed)');
        }
    }
    sort($results);
    if (! $waiting || $results !== ['accepted', 'denied']) {
        throw new RuntimeException('AUTH3 race invariant failed');
    }
    echo ($recovery ? 'Recovery' : 'TOTP').": two service processes, two blocked PG PIDs, exactly one winner PASS\n";
}

// Справжній middleware та logout-all service: revoke чекає до бізнес-запису/commit.
$request = Request::create('/admin/clients');
$request->setLaravelSession(app('session')->driver());
$request->session()->start();
$request->session()->put('mfa_binding', $bindings[0]);
app()->instance('request', $request);
$login = $mfa->beginPasswordLogin('auth3-race@example.invalid', 'synthetic-race', $bindings[0]);
$proof = $mfa->finishLogin($login, $bindings[0], $codes[1], true);
app(AdminAssurance::class)->issue($request, $proof);
$revokeFlow = $mfa->beginPasswordLogin('auth3-race@example.invalid', 'synthetic-race', $bindings[1]);
$version = DB::table('users')->where('id', 8310000000)->value('auth_version');
$process = null;
$pipes = [];
$card = app(RequireAdminMfa::class)->handle($request, function () use (&$process, &$pipes, $revokeFlow, $bindings, $codes, $version) {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['action' => 'logout_all', 'flow' => $revokeFlow, 'binding' => $bindings[1], 'code' => $codes[2]]));
    fclose($pipes[0]);
    $waiting = false;
    for ($n = 0; $n < 100; $n++) {
        DB::select('SELECT pg_stat_clear_snapshot()');
        if (DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name='auth3_gate_revoker' AND wait_event_type='Lock'")->n === 1) {
            $waiting = true;
            break;
        }
        usleep(100000);
    }
    if (! $waiting || DB::table('users')->where('id', 8310000000)->value('auth_version') !== $version) {
        throw new RuntimeException('Gate user lock released before business operation');
    }

    return DB::table('clients')->insertGetId(['coach_user_id' => 8310000000]);
});
$result = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || $result !== 'accepted' || $error !== ''
    || ! DB::table('clients')->where('id', $card)->exists()
    || DB::table('users')->where('id', 8310000000)->value('auth_version') !== $version + 1) {
    throw new RuntimeException('Gate/logout-all race failed');
}
try {
    app(RequireAdminMfa::class)->handle($request, fn () => throw new RuntimeException('Revoked request reached business action'));
    throw new RuntimeException('Revoked request accepted');
} catch (HttpException $exception) {
    if ($exception->getStatusCode() !== 403) {
        throw $exception;
    }
}
echo "Gate business transaction vs logout-all: PG revoke waited for business write/commit; subsequent request 403 PASS\n";
