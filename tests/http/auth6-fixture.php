<?php

use App\Mail\EmailOtp;
use App\Services\Auth\AdminAssurance;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSecurity;
use App\Services\Auth\Client\ClientSession;
use App\Services\Auth\MfaService;
use App\Support\Auth\AuthSecretCodec;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'AUTH6 fixture failed: '.get_class($e).' line '.$e->getLine()."\n");
    exit(1);
});
config(['session.encrypt' => true, 'session.cookie' => 'telmanfit_client_session', 'session.lifetime' => 720, 'queue.default' => 'sync', 'mail.default' => 'array']);
Mail::fake();
$input = json_decode(stream_get_contents(STDIN), true) ?? [];
if (($argv[1] ?? '') === 'waiting') {
    echo json_encode(DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name='auth6_http' AND wait_event_type='Lock'")->n);
    exit;
}
if (($argv[1] ?? '') === 'inspect') {
    $data = json_decode(Crypt::decrypt(base64_decode(DB::table('sessions')->where('id', $input['id'])->value('payload') ?? '')), true);
    echo json_encode($data['auth6_probe'] ?? []);
    exit;
}
if (($input['action'] ?? '') === 'admin_logout') {
    config(['session.cookie' => 'telmanfit_admin_session', 'session.lifetime' => 15]);
    $request = Request::create('/admin', 'GET');
    app()->instance('request', $request);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->start();
    $binding = bin2hex(random_bytes(32));
    $request->session()->put('mfa_binding', $binding);
    $email = 'auth6-admin-'.bin2hex(random_bytes(6)).'@example.invalid';
    $password = 'synthetic-admin-password';
    $id = DB::table('users')->insertGetId(['name' => 'AUTH6 HTTP admin', 'email' => $email, 'role' => 'admin', 'password' => Hash::make($password)]);
    $secret = (new Google2FA)->generateSecretKey(32);
    DB::table('user_totp_factors')->insert(['user_id' => $id, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret), 'key_version' => 'test_v1', 'created_at' => now()->subMinute(), 'confirmed_at' => now()]);
    $mfa = app(MfaService::class);
    $flow = $mfa->beginPasswordLogin($email, $password, $binding);
    $code = (new Google2FA)->oathTotp($secret, intdiv(time(), 30));
    $proof = $mfa->finishLogin($flow, $binding, $code);
    app(AdminAssurance::class)->issue($request, $proof);
    $name = 'telmanfit_admin_session';
    $cookie = Crypt::encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$request->session()->getId(), false);
    echo json_encode(['cookie' => $name.'='.rawurlencode($cookie), 'token' => $request->session()->token(), 'id' => $request->session()->getId()]);
    exit;
}
Cache::store('database')->flush();
$coach = DB::table('users')->where('email', 'auth6-http-coach@example.invalid')->value('id')
    ?? DB::table('users')->insertGetId(['name' => 'AUTH6 HTTP coach', 'role' => 'admin', 'email' => 'auth6-http-coach@example.invalid']);
$email = 'auth6-'.bin2hex(random_bytes(6)).'@example.invalid';
$id = DB::table('users')->insertGetId(['name' => 'AUTH6 HTTP client', 'email' => $email, 'email_normalized' => $email, 'email_verified_at' => now()]);
DB::table('clients')->insert(['user_id' => $id, 'coach_user_id' => $coach]);
$request = Request::create('/api/me', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
app()->instance('request', $request);
$request->setLaravelSession(app('session')->driver());
$request->session()->start();
$binding = bin2hex(random_bytes(32));
$request->session()->put('client_binding', $binding);
$auth = app(ClientAuth::class);
$otp = function () {
    preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);

    return $match[1];
};
$login = $auth->request('login', $email, null, $binding, '127.0.0.1');
$result = $auth->verify($login['challenge_id'], $otp(), $binding, '127.0.0.1');
app(ClientSession::class)->issue($request, $result['proof']);
$security = app(ClientSecurity::class);
$action = $input['action'] ?? 'rotate';
$operation = ['path' => '/api/auth/logout', 'data' => []];
if (in_array($action, ['rotate', 'revoke', 'email', 'logout_all'])) {
    $grant = $security->requestReauth($request, match ($action) {
        'revoke' => 'revoke_session', 'email' => 'email_change', 'logout_all' => 'logout_all', default => 'enable_factor',
    });
    $code = $otp();
    if ($action === 'rotate') {
        $operation = ['path' => '/api/auth/security/reauth/verify', 'data' => ['challenge_id' => $grant['challenge_id'], 'code' => $code]];
    } else {
        $proof = $security->verifyReauth($request, $grant['challenge_id'], $code)['flow_id'];
        if ($action === 'revoke') {
            $handle = $security->sessions($request)[0]['handle'];
            $operation = ['path' => '/api/auth/security/sessions/revoke', 'data' => ['proof_id' => $proof, 'handle' => $handle]];
        } elseif ($action === 'email') {
            $operation = ['path' => '/api/auth/security/email/request', 'data' => ['proof_id' => $proof, 'email' => 'new-'.$email]];
        } else {
            $operation = ['path' => '/api/auth/security/logout-all', 'data' => ['proof_id' => $proof]];
        }
    }
} elseif ($action === 'reauth') {
    $operation = ['path' => '/api/auth/security/reauth/request', 'data' => ['action' => 'enable_factor']];
}
$request->session()->save();
$name = 'telmanfit_client_session';
$cookie = Crypt::encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$request->session()->getId(), false);
echo json_encode(['cookie' => $name.'='.rawurlencode($cookie), 'token' => $request->session()->token(), 'id' => $request->session()->getId(), 'operation' => $operation]);
