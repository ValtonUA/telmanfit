<?php

use App\Services\Auth\Client\ControlledAuth;
use App\Support\Auth\AuthSecretCodec;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function () {
    fwrite(STDERR, "AUTH4 synthetic fixture failure (details suppressed)\n");
    exit(1);
});
$mode = $argv[1] ?? 'setup';
if ($mode === 'mail') {
    $email = $argv[2];
    $lines = file(config('auth4.synthetic_mailbox'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_reverse($lines) as $line) {
        $message = json_decode(Crypt::decryptString($line), true, flags: JSON_THROW_ON_ERROR);
        if (strtolower($message['to']) === strtolower($email) && preg_match('/<strong>([0-9]{8})<\/strong>/', $message['html'], $match)) {
            echo $match[1];
            exit;
        }
    }
    exit(1);
}
if ($mode === 'totp') {
    $id = $argv[2] === 'admin' ? 8420000000 : 8420000001;
    $factor = DB::table('user_totp_factors')->where('user_id', $id)->first();
    if ((int) ($argv[3] ?? 0) === -1 && time() % 30 >= 26) {
        usleep((31 - time() % 30) * 1000000);
    }
    echo (new Google2FA)->oathTotp(app(AuthSecretCodec::class)->decryptTotp($factor->secret_ciphertext), intdiv(time(), 30) + (int) ($argv[3] ?? 0));
    exit;
}
if ($mode === 'inspect') {
    $user = DB::table('users')->where('email_normalized', 'browser-new@example.invalid')->first();
    $client = DB::table('clients')->where('user_id', $user->id)->first();
    if ($user->role !== 'client' || $user->password !== null || ! $user->email_verified_at || $client->status !== 'pending' || $client->coaching_started_at !== null) {
        exit(1);
    }
    echo 'pending-verified-client';
    exit;
}
if ($mode === 'cooldown') {
    $key = 'auth4:'.app(ControlledAuth::class)->fingerprint('cooldown:login:browser-new@example.invalid');
    Cache::store('database')->forget($key);
    exit;
}
if ($mode === 'unused-id') {
    echo DB::table('client_invitations')->insertGetId(['token_hash' => hash('sha256', random_bytes(32)), 'created_by_user_id' => 8420000000, 'coach_user_id' => 8420000000]);
    exit;
}
if ($mode === 'version') {
    DB::table('users')->where('id', 8420000001)->increment('auth_version');
    exit;
}
Cache::store('database')->forget('auth3:'.hash('sha256', '127.0.0.1'));
Cache::store('database')->forget('auth3:'.hash('sha256', '127.0.0.1').':timer');
DB::table('users')->insert([
    ['id' => 8420000000, 'name' => 'AUTH4 браузер admin', 'role' => 'admin', 'email' => 'browser-admin4@example.invalid', 'email_normalized' => 'browser-admin4@example.invalid', 'email_verified_at' => now(), 'password' => Hash::make('Synthetic-AUTH4-only!')],
    ['id' => 8420000001, 'name' => 'AUTH4 браузер MFA client', 'role' => 'client', 'email' => 'browser-mfa4@example.invalid', 'email_normalized' => 'browser-mfa4@example.invalid', 'email_verified_at' => now(), 'password' => null],
]);
DB::table('clients')->insert(['user_id' => 8420000001, 'coach_user_id' => 8420000000]);
foreach ([8420000000, 8420000001] as $id) {
    $secret = (new Google2FA)->generateSecretKey(32);
    DB::table('user_totp_factors')->insert(['user_id' => $id, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret),
        'key_version' => 'test_v1', 'created_at' => now()->subMinute(), 'confirmed_at' => app(ControlledAuth::class)->stamp()]);
}
echo "AUTH4 synthetic browser fixtures ready\n";
