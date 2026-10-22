<?php

use App\Services\Auth\Client\ControlledAuth;
use App\Support\Auth\AuthSecretCodec;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'AUTH5 synthetic fixture failure: '.get_class($error).' line '.$error->getLine()." (details suppressed)\n");
    exit(1);
});
$mode = $argv[1] ?? 'setup';
if ($mode === 'queue-health') {
    echo json_encode(['challenges' => DB::table('auth_challenges')->whereNull('consumed_at')->whereNull('invalidated_at')->count(),
        'pending_flows' => DB::table('auth_flows')->where('state', 'pending')->count(), 'jobs' => DB::table('jobs')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(), 'mailbox_exists' => is_file(config('auth4.synthetic_mailbox'))]);
    exit;
}
if ($mode === 'mail') {
    $email = $argv[2];
    foreach (array_reverse(file(config('auth4.synthetic_mailbox'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
        $message = json_decode(Crypt::decryptString($line), true, flags: JSON_THROW_ON_ERROR);
        if (strtolower($message['to']) === strtolower($email) && preg_match('/<strong>([0-9]{8})<\/strong>/', $message['html'], $match)) {
            echo $match[1];
            exit;
        }
    }
    exit(1);
}
if ($mode === 'totp') {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $factor = DB::table('user_totp_factors')->where('user_id', DB::table('users')->where('email_normalized', $input['email'])->value('id'))->first();
    $secret = app(AuthSecretCodec::class)->decryptTotp($input['pending'] ?? false ? $factor->pending_secret_ciphertext : $factor->secret_ciphertext);
    while (true) {
        $step = max(intdiv(time(), 30) - 1, ($factor->last_used_step ?? 0) + 1);
        if ($step <= intdiv(time(), 30) + 1 && ! ($step === intdiv(time(), 30) - 1 && time() % 30 > 25)) {
            break;
        }
        usleep(100000);
    }
    echo (new Google2FA)->oathTotp($secret, $step);
    exit;
}
if ($mode === 'window') {
    $email = $argv[2];
    if (! str_ends_with($email, '@example.invalid')) {
        exit(1);
    }
    // Прискорений плин 15 хвилин тільки у synthetic browser fixture.
    foreach (['login', 'reauth', 'email_change', 'register'] as $purpose) {
        foreach (['cooldown:'.$purpose.':'.$email, 'issue:15:'.$purpose.':'.$email, 'issue:day:'.$purpose.':'.$email, 'issue:all:'.$email] as $key) {
            Cache::store('database')->forget('auth4:'.app(ControlledAuth::class)->fingerprint($key));
        }
    }
    foreach (['verify:ip:127.0.0.1', 'issue:ip:15:127.0.0.1'] as $key) {
        Cache::store('database')->forget('auth4:'.app(ControlledAuth::class)->fingerprint($key));
    }
    exit;
}
if ($mode === 'invitation') {
    $token = bin2hex(random_bytes(32));
    $row = ['token_hash' => hash('sha256', $token), 'created_by_user_id' => 8520000000, 'coach_user_id' => 8520000000,
        'created_at' => app(ControlledAuth::class)->now()->subMinute()->format('Y-m-d H:i:s.uP')];
    if (($argv[2] ?? '') === 'revoked') {
        $row += ['revoked_at' => app(ControlledAuth::class)->stamp(), 'revoked_by_user_id' => 8520000000];
    }
    if (($argv[2] ?? '') === 'consumed') {
        $row += ['consumed_at' => app(ControlledAuth::class)->stamp(), 'consumed_by_user_id' => 8520000000];
    }
    DB::table('client_invitations')->insert($row);
    echo $token;
    exit;
}
if ($mode === 'expire-otp') {
    DB::statement('ALTER TABLE auth_challenges DISABLE TRIGGER auth2_challenge_transition');
    DB::table('auth_challenges')->where('public_id', $argv[2])->update(['created_at' => now()->subMinutes(10), 'expires_at' => now()->subMinute()]);
    DB::statement('ALTER TABLE auth_challenges ENABLE TRIGGER auth2_challenge_transition');
    exit;
}
if ($mode === 'cache-outage') {
    DB::statement('ALTER TABLE cache RENAME TO auth5_browser_cache_outage');
    exit;
}
if ($mode === 'cache-restore') {
    DB::statement('ALTER TABLE auth5_browser_cache_outage RENAME TO cache');
    exit;
}
if ($mode === 'expire-session') {
    $id = DB::table('users')->where('email_normalized', $argv[2])->value('id');
    DB::table('sessions')->whereIn('id', DB::table('auth_session_states')->where('user_id', $id)->select('session_id'))->update(['last_activity' => time() - 43201]);
    exit;
}
DB::table('users')->insert(['id' => 8520000000, 'name' => 'AUTH5 браузер admin', 'role' => 'admin',
    'email' => 'auth5-admin@example.invalid', 'email_normalized' => 'auth5-admin@example.invalid', 'email_verified_at' => now(),
    'password' => Hash::make('Synthetic-AUTH5-only!')]);
$secret = (new Google2FA)->generateSecretKey(32);
DB::table('user_totp_factors')->insert(['user_id' => 8520000000, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret),
    'key_version' => 'test_v1', 'created_at' => now()->subMinute(), 'confirmed_at' => app(ControlledAuth::class)->stamp()]);
echo "AUTH5 synthetic browser fixtures ready\n";
