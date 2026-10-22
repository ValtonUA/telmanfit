<?php

use App\Http\Middleware\SerializedAuthSession;
use App\Services\Auth\Retention\RetentionService;
use App\Support\Session\ContextDatabaseSessionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'B5 hold race failed: '.get_class($e).' line '.$e->getLine()."\n");
    exit(1);
});
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='b5_hold_".$input['action']."'");
    if ($input['action'] === 'gc') {
        echo (new ContextDatabaseSessionHandler(DB::connection(), 'sessions', 720, app()))->gc(43200);
    } elseif ($input['action'] === 'save') {
        // Справжній B1 middleware: follower зі знищеним ID не входить у save/Set-Cookie.
        config(['auth4.enabled' => true, 'session.cookie' => 'telmanfit_client_session']);
        $request = Request::create('/api/me', 'GET', cookies: ['telmanfit_client_session' => $input['session']]);
        app()->instance('request', $request);
        $response = app(SerializedAuthSession::class)->handle($request, fn () => throw new RuntimeException('deleted_session_reached_save'));
        echo $response->getStatusCode().':'.count($response->headers->getCookies());
    } elseif ($input['action'] === 'cleanup') {
        DB::statement('SET ROLE telmanfit_retention_executor');
        echo DB::selectOne("SELECT auth_retention_cleanup(?::uuid,?,'pending_totp') n", [$input['run'], $input['batch']])->n;
    }
    exit;
}
function spawnHoldRace(array $input): array
{
    $p = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input)."\n");
    fflush($pipes[0]);

    return [$p, $pipes];
}
function finishHoldRace(array $worker): string
{
    [$p, $pipes] = $worker;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0 || $err !== '') {
        throw new RuntimeException('hold_worker_failed');
    }

    return $out;
}
function waitHoldRace(string $action): void
{
    for ($i = 0; $i < 100; $i++) {
        DB::select('SELECT pg_stat_clear_snapshot()');
        if (DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", ['b5_hold_'.$action])->n > 0) {
            return;
        }
        usleep(10000);
    }
    throw new RuntimeException('hold_wait_not_observed');
}
$user = DB::table('users')->insertGetId(['name' => 'Synthetic held session race', 'role' => 'client']);
$principal = (string) Str::uuid();
DB::table('auth_recovery_principals')->insert(['id' => $principal, 'authority' => 'reviewer',
    'identity_record_digest' => str_repeat('a', 64), 'created_at' => DB::raw('clock_timestamp()')]);
$session = Str::random(40);
DB::table('sessions')->insert(['id' => $session, 'user_id' => $user, 'payload' => 'synthetic-held-payload', 'last_activity' => time() - 50000]);
DB::table('auth_session_states')->insert(['session_id' => $session, 'user_id' => $user, 'audience' => 'client',
    'browser_binding_hash' => str_repeat('b', 64), 'auth_version' => 0, 'primary_method' => 'email_otp',
    'primary_verified_at' => DB::raw("clock_timestamp()-interval '1 day'"), 'absolute_expires_at' => DB::raw("clock_timestamp()+interval '1 hour'")]);
// Hold commit → GC → пізній HTTP follower. GC тримає session lock під policy wait.
DB::beginTransaction();
DB::table('auth_retention_holds')->insert(['id' => (string) Str::uuid(), 'subject_user_id' => $user,
    'reason_code' => 'legal', 'created_by_principal_id' => $principal]);
$gc = spawnHoldRace(['action' => 'gc']);
waitHoldRace('gc');
$save = spawnHoldRace(['action' => 'save', 'session' => $session]);
waitHoldRace('save');
DB::commit();
if ((int) finishHoldRace($gc) < 1 || finishHoldRace($save) !== '401:0'
    || DB::table('sessions')->where('id', $session)->exists()
    || DB::table('auth_session_states')->where('session_id', $session)->exists()
    || DB::table('auth_audit_events')->where('event_type', 'retention.session_scrubbed')->where('session_fingerprint', hash('sha256', $session))->count() !== 1) {
    throw new RuntimeException('held_gc_save_resurrection');
}
echo "B5 held session create/GC/HTTP save race PASS: observed policy and session waits, atomic metadata, no resurrection or Set-Cookie\n";

// Save першим бере B1 lock; GC skip, після commit повторно перевіряє live TTL.
$session = Str::random(40);
DB::table('sessions')->insert(['id' => $session, 'user_id' => $user, 'payload' => 'synthetic-refreshed', 'last_activity' => time() - 50000]);
DB::beginTransaction();
DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,606))', [hash('sha256', $session)]);
DB::table('sessions')->where('id', $session)->update(['last_activity' => time()]);
finishHoldRace(spawnHoldRace(['action' => 'gc']));
if (! DB::table('sessions')->where('id', $session)->exists()) {
    throw new RuntimeException('held_save_lock_ignored');
}
DB::commit();
finishHoldRace(spawnHoldRace(['action' => 'gc']));
if (! DB::table('sessions')->where('id', $session)->exists()
    || DB::table('auth_audit_events')->where('event_type', 'retention.session_scrubbed')->where('session_fingerprint', hash('sha256', $session))->exists()) {
    throw new RuntimeException('held_live_session_scrubbed');
}
echo "B5 held session save/GC race PASS: busy ID skipped, refreshed TTL preserved without false metadata\n";

DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
    'confirmed_at' => DB::raw("clock_timestamp()-interval '1 hour'"), 'last_used_step' => 777,
    'pending_secret_ciphertext' => str_repeat('p', 100), 'pending_key_version' => 'test_v1',
    'pending_expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
$run = app(RetentionService::class)->start();
DB::beginTransaction();
// Factor-only lock також захищає від writer поза user-lock protocol.
DB::table('user_totp_factors')->where('user_id', $user)->lockForUpdate()->first();
if (finishHoldRace(spawnHoldRace(['action' => 'cleanup', 'run' => $run, 'batch' => 1])) !== '0') {
    throw new RuntimeException('held_factor_lock_not_skipped');
}
DB::table('user_totp_factors')->where('user_id', $user)->update(['secret_ciphertext' => str_repeat('p', 100), 'key_version' => 'test_v1',
    'confirmed_at' => DB::raw('clock_timestamp()'), 'last_used_step' => 778,
    'pending_secret_ciphertext' => null, 'pending_key_version' => null, 'pending_expires_at' => null, 'updated_at' => DB::raw('clock_timestamp()')]);
DB::commit();
if (finishHoldRace(spawnHoldRace(['action' => 'cleanup', 'run' => $run, 'batch' => 2])) !== '0'
    || DB::table('user_totp_factors')->where('user_id', $user)->value('secret_ciphertext') !== str_repeat('p', 100)
    || DB::table('user_totp_factors')->where('user_id', $user)->value('last_used_step') !== 778) {
    throw new RuntimeException('held_confirmation_lost');
}
echo "B5 held pending/confirmation race PASS: factor lock skip, confirmed material and monotonic watermark preserved\n";
