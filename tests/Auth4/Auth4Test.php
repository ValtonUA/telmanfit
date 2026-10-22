<?php

namespace Tests\Auth4;

use App\Jobs\DeliverEmailOtp;
use App\Mail\EmailOtp;
use App\Services\Auth\AdminAssurance;
use App\Services\Auth\Client\AuthBudget;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\Client\EmailInventory;
use App\Services\Auth\Client\EmailNormalizer;
use App\Services\Auth\Client\InvitationService;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use App\Services\Auth\SecretValue;
use App\Support\Auth\AuthSecretCodec;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class Auth4Test extends TestCase
{
    private ClientAuth $auth;

    private string $binding;

    private int $coach = 8400000000;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        DB::table('users')->insert(['id' => $this->coach, 'name' => 'AUTH4 coach', 'role' => 'admin']);
        $this->auth = app(ClientAuth::class);
        $this->binding = bin2hex(random_bytes(32));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function invitation(): string
    {
        $token = bin2hex(random_bytes(32));
        DB::table('client_invitations')->insert(['token_hash' => hash('sha256', $token), 'created_by_user_id' => $this->coach, 'coach_user_id' => $this->coach]);

        return $token;
    }

    private function registration(string $email = ' New+tag@Example.invalid '): array
    {
        $token = $this->invitation();
        $flow = $this->auth->exchange($token, $this->binding, '192.0.2.1')['flow_id'];
        [$challenge, $code] = $this->requestCode('register', $email, $flow);

        return [$token, $flow, $challenge, $code];
    }

    private function requestCode(string $purpose, string $email, ?string $flow = null): array
    {
        $result = $this->auth->request($purpose, $email, $flow, $this->binding, '192.0.2.1');
        $mail = Mail::sent(EmailOtp::class)->last();
        $this->assertNotNull($mail);
        preg_match('/<strong>([0-9]{8})<\/strong>/', $mail->render(), $match);

        return [$result['challenge_id'], $match[1]];
    }

    private function denied(callable $action, string $code = 'invalid_challenge'): void
    {
        try {
            $action();
            $this->fail('Expected denial');
        } catch (AuthDenied $e) {
            $this->assertSame($code, $e->reason);
        }
    }

    private function requestSession(): Request
    {
        $request = Request::create('/api/me');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->setName('telmanfit_client_session');
        $request->session()->start();
        $request->session()->put('client_binding', $this->binding);
        $request->session()->save();
        app()->instance('request', $request);

        return $request;
    }

    public function test_email_contract_idna_case_plus_dots_and_rejection(): void
    {
        $normalizer = app(EmailNormalizer::class);
        $this->assertSame('a.b+tag@example.invalid', $normalizer->normalize(' A.B+Tag@Example.Invalid '));
        $this->assertSame('a@xn--bcher-kva.example', $normalizer->normalize('A@bücher.example'));
        foreach (['ї@example.invalid', 'a..b@example.invalid', 'bad', 'a@bad domain', str_repeat('a', 255).'@example.invalid'] as $invalid) {
            $this->denied(fn () => $normalizer->normalize($invalid), 'invalid_email');
        }
    }

    public function test_registration_is_atomic_pending_and_preserves_null_cards(): void
    {
        DB::table('clients')->insert(['coach_user_id' => $this->coach]);
        [$token, $flow, $challenge, $code] = $this->registration();
        $this->assertNull(DB::table('client_invitations')->where('token_hash', hash('sha256', $token))->value('consumed_at'));
        $this->assertSame('registration_complete', $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1')['next']);
        $proof = $this->auth->complete($flow, 'Новий клієнт', 'New+tag@Example.invalid', $this->binding, '192.0.2.1');
        $request = $this->requestSession();
        $oldId = $request->session()->getId();
        $oldCsrf = $request->session()->token();
        app(ClientSession::class)->issue($request, $proof);
        $this->assertNotSame($oldId, $request->session()->getId());
        $this->assertNotSame($oldCsrf, $request->session()->token());
        $user = app(ClientSession::class)->user($request);
        $this->assertSame('New+tag@Example.invalid', $user->email);
        $this->assertSame('new+tag@example.invalid', $user->email_normalized);
        $this->assertSame('client', $user->role);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $client = DB::table('clients')->where('user_id', $user->id)->first();
        $this->assertSame('pending', $client->status);
        $this->assertNull($client->coaching_started_at);
        $this->assertSame($this->coach, $client->coach_user_id);
        $this->assertSame(1, DB::table('clients')->where('coach_user_id', $this->coach)->whereNull('user_id')->count());
        $this->assertSame(0, DB::table('client_onboardings')->count());
        $this->denied(fn () => $this->auth->complete($flow, 'again', 'New+tag@Example.invalid', $this->binding, '192.0.2.1'), 'invitation_unavailable');
        $this->denied(fn () => app(ClientSession::class)->issue($request, $proof));
        app(ClientSession::class)->logout($request);
        $this->denied(fn () => app(ClientSession::class)->user($request), 'unauthenticated');
    }

    public function test_otp_wrong_browser_attempts_hmac_replay_and_version(): void
    {
        [, $flow, $challenge, $code] = $this->registration();
        $this->denied(fn () => $this->auth->verify($challenge, $code, 'foreign-browser', '192.0.2.2'));
        $this->assertSame(0, DB::table('auth_challenges')->where('public_id', $challenge)->value('attempts'));
        for ($i = 0; $i < 5; $i++) {
            $this->denied(fn () => $this->auth->verify($challenge, 'wrong', $this->binding, '192.0.2.1'));
        }
        $this->assertSame(5, DB::table('auth_challenges')->where('public_id', $challenge)->value('attempts'));
        $this->denied(fn () => $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1'), 'rate_limited');
        $this->assertSame('pending', DB::table('auth_flows')->where('public_id', $flow)->value('state'));
    }

    public function test_resend_and_new_flow_do_not_reset_email_budget(): void
    {
        [, $flow, $challenge, $code] = $this->registration();
        $suppressed = $this->auth->request('register', 'new+tag@example.invalid', $flow, $this->binding, '192.0.2.1');
        $this->assertFalse(DB::table('auth_challenges')->where('public_id', $suppressed['challenge_id'])->exists());
        Mail::assertSentCount(1);
        // Лише synthetic clock у cache; challenge/flow час незмінний.
        DB::table('cache')->where('key', 'like', '%auth4:%')->update(['expiration' => time() - 1]);
        [$newChallenge, $newCode] = $this->requestCode('register', 'new+tag@example.invalid', $flow);
        $this->denied(fn () => $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1'));
        $this->assertSame('registration_complete', $this->auth->verify($newChallenge, $newCode, $this->binding, '192.0.2.1')['next']);
        $this->denied(fn () => $this->auth->verify($newChallenge, $newCode, $this->binding, '192.0.2.1'));
    }

    public function test_historical_unverified_email_cannot_be_taken_over_or_logged_in(): void
    {
        DB::table('users')->insert(['name' => 'Historical', 'email' => 'NEW+TAG@example.invalid']);
        [, $flow, $challenge, $code] = $this->registration();
        $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1');
        $this->denied(fn () => $this->auth->complete($flow, 'takeover', 'new+tag@example.invalid', $this->binding, '192.0.2.1'), 'registration_conflict');
        $this->assertNull(DB::table('client_invitations')->where('coach_user_id', $this->coach)->value('consumed_at'));
        $response = $this->auth->request('login', 'new+tag@example.invalid', null, $this->binding, '192.0.2.1');
        $this->assertFalse(DB::table('auth_challenges')->where('public_id', $response['challenge_id'])->exists());
        Mail::assertSentCount(1);
    }

    public function test_revoked_invitation_and_expired_otp_fail(): void
    {
        [$token, , $challenge, $code] = $this->registration();
        DB::statement('ALTER TABLE auth_challenges DISABLE TRIGGER auth2_challenge_transition');
        DB::table('auth_challenges')->where('public_id', $challenge)->update(['created_at' => now()->subMinutes(10), 'expires_at' => now()->subMinute()]);
        DB::statement('ALTER TABLE auth_challenges ENABLE TRIGGER auth2_challenge_transition');
        $this->denied(fn () => $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1'));
        DB::table('client_invitations')->where('token_hash', hash('sha256', $token))->update(['revoked_at' => app(ControlledAuth::class)->stamp(), 'revoked_by_user_id' => $this->coach]);
        $this->denied(fn () => $this->auth->exchange($token, $this->binding, '192.0.2.1'), 'invitation_unavailable');
    }

    public static function registrationFailureTables(): array
    {
        return [['users'], ['clients'], ['auth_audit_events']];
    }

    #[DataProvider('registrationFailureTables')]
    public function test_registration_rolls_back_users_clients_invitation_on_write_failure(string $table): void
    {
        [, $flow, $challenge, $code] = $this->registration();
        $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1');
        DB::statement("CREATE FUNCTION auth4_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic audit failure'; END $$");
        DB::statement('CREATE TRIGGER auth4_fault BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION auth4_fault()');
        try {
            $this->auth->complete($flow, 'rollback', 'new+tag@example.invalid', $this->binding, '192.0.2.1');
            $this->fail('Expected rollback');
        } catch (QueryException) {
            $this->assertFalse(DB::table('users')->where('email_normalized', 'new+tag@example.invalid')->exists());
            $this->assertSame(0, DB::table('clients')->where('coach_user_id', $this->coach)->count());
            $this->assertNull(DB::table('client_invitations')->where('coach_user_id', $this->coach)->value('consumed_at'));
            $this->assertSame('primary_verified', DB::table('auth_flows')->where('public_id', $flow)->value('state'));
        }
    }

    public function test_synthetic_backfill_is_fail_closed_and_does_not_verify_addresses(): void
    {
        DB::table('users')->insert(['name' => 'one', 'email' => 'One@Example.invalid']);
        app(EmailInventory::class)->syntheticBackfill();
        $this->assertSame('one@example.invalid', DB::table('users')->where('name', 'one')->value('email_normalized'));
        $this->assertNull(DB::table('users')->where('name', 'one')->value('email_verified_at'));
        DB::table('users')->insert(['name' => 'collision', 'email' => 'ONE@example.invalid']);
        $before = DB::table('users')->get()->toJson();
        $this->denied(fn () => app(EmailInventory::class)->syntheticBackfill(), 'backfill_blocked');
        $this->assertSame($before, DB::table('users')->get()->toJson());
    }

    public function test_client_totp_uses_shared_mfa_and_restricted_state(): void
    {
        $id = DB::table('users')->insertGetId(['name' => 'MFA client', 'role' => 'client', 'email' => 'mfa@example.invalid', 'email_normalized' => 'mfa@example.invalid', 'email_verified_at' => now()]);
        DB::table('clients')->insert(['user_id' => $id, 'coach_user_id' => $this->coach]);
        $secret = (new Google2FA)->generateSecretKey(32);
        DB::table('user_totp_factors')->insert(['user_id' => $id, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret), 'key_version' => 'test_v1', 'confirmed_at' => now(), 'created_at' => now()->subMinute()]);
        [$challenge, $code] = $this->requestCode('login', 'mfa@example.invalid');
        $response = $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1');
        $this->assertSame('mfa_required', $response['next']);
        $request = $this->requestSession();
        $this->denied(fn () => app(ClientSession::class)->user($request), 'unauthenticated');
        $proof = app(MfaService::class)->finishLogin($response['flow_id'], $this->binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30)));
        app(ClientSession::class)->issue($request, $proof);
        $this->assertSame($id, app(ClientSession::class)->user($request)->id);
        Cache::store('database')->forget('auth4:'.app(ControlledAuth::class)->fingerprint('cooldown:login:mfa@example.invalid'));
        [$logoutChallenge, $logoutCode] = $this->requestCode('login', 'mfa@example.invalid');
        $logoutFlow = $this->auth->verify($logoutChallenge, $logoutCode, $this->binding, '192.0.2.1')['flow_id'];
        app(MfaService::class)->logoutAll($logoutFlow, $this->binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30) + 1));
        $this->assertSame(1, DB::table('users')->where('id', $id)->value('auth_version'));
        $this->denied(fn () => app(ClientSession::class)->user($request), 'unauthenticated');
    }

    public function test_untrusted_proof_and_admin_guard_never_issue_client_session(): void
    {
        $request = $this->requestSession();
        Auth::guard('admin')->loginUsingId($this->coach);
        $this->denied(fn () => app(ClientSession::class)->user($request), 'unauthenticated');
        $this->denied(fn () => app(ClientSession::class)->issue($request, new SecretValue('forged')));
        try {
            app(MfaService::class)->beginVerifiedPasswordLogin(new SecretValue('forged'), $this->binding);
            $this->fail('Untrusted password proof accepted');
        } catch (MfaDenied) {
            $this->addToAssertionCount(1);
        }
        $this->denied(fn () => app(InvitationService::class)->create($request, $this->coach, 'wrong', '000000'), 'reauth_required');
    }

    public function test_queue_payload_is_encrypted_and_key_version_is_strict(): void
    {
        [, , $challenge, $code] = $this->registration();
        $job = app('queue')->connection('database')->push(new DeliverEmailOtp($challenge, 'new@example.invalid', $code));
        $payload = DB::table('jobs')->where('id', $job)->value('payload');
        $this->assertStringNotContainsString($code, $payload);
        $this->assertStringNotContainsString('new@example.invalid', $payload);
        config(['auth4.keys.test_v1' => null]);
        $this->denied(fn () => $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1'), 'auth_unavailable');
    }

    public function test_http_gates_no_store_cookie_and_fail_closed_outages(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/auth/csrf')->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        config(['auth4.enabled' => false]);
        $this->getJson('/api/auth/csrf')->assertNotFound();
        config(['auth4.enabled' => true, 'mail.default' => 'log']);
        $this->getJson('/api/auth/csrf')->assertStatus(503)->assertJsonPath('code', 'auth_unavailable');
        config(['mail.default' => 'array']);
        DB::statement('ALTER TABLE cache RENAME TO auth4_cache_outage');
        $this->postJson('/api/auth/invitations/exchange', ['token' => bin2hex(random_bytes(32))])->assertStatus(503);
    }

    public function test_issuance_limits_and_failure_budget_survive_new_registration_flow(): void
    {
        [, $flow, $challenge] = $this->registration();
        for ($i = 0; $i < 4; $i++) {
            $this->denied(fn () => $this->auth->verify($challenge, 'wrong', $this->binding, '192.0.2.1'));
        }
        $policy = app(ControlledAuth::class);
        Cache::store('database')->forget('auth4:'.$policy->fingerprint('cooldown:register:new+tag@example.invalid'));
        $other = $this->auth->exchange($this->invitation(), $this->binding, '192.0.2.1')['flow_id'];
        [$next, $code] = $this->requestCode('register', 'new+tag@example.invalid', $other);
        $this->denied(fn () => $this->auth->verify($next, 'wrong', $this->binding, '192.0.2.1'));
        $this->denied(fn () => $this->auth->verify($next, $code, $this->binding, '192.0.2.1'), 'rate_limited');
        $this->assertTrue(app(AuthBudget::class)->blocked('new+tag@example.invalid'));
    }

    public function test_three_sends_per_quarter_hour_and_unknown_email_share_queue_failure(): void
    {
        [, $flow] = $this->registration();
        $policy = app(ControlledAuth::class);
        for ($i = 0; $i < 3; $i++) {
            Cache::store('database')->forget('auth4:'.$policy->fingerprint('cooldown:register:new+tag@example.invalid'));
            $this->auth->request('register', 'new+tag@example.invalid', $flow, $this->binding, '192.0.2.1');
        }
        Mail::assertSentCount(3);
        $this->assertSame(3, DB::table('auth_challenges')->where('email_normalized', 'new+tag@example.invalid')->count());
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('synthetic queue failure'));
        $this->denied(fn () => $this->auth->request('login', 'unknown@example.invalid', null, $this->binding, '192.0.2.1'), 'auth_unavailable');
    }

    public function test_queue_failure_invalidates_real_challenge_without_leaking_code(): void
    {
        $flow = $this->auth->exchange($this->invitation(), $this->binding, '192.0.2.1')['flow_id'];
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('synthetic queue failure'));
        $this->denied(fn () => $this->auth->request('register', 'queue-failure@example.invalid', $flow, $this->binding, '192.0.2.1'), 'auth_unavailable');
        $this->assertNotNull(DB::table('auth_challenges')->where('email_normalized', 'queue-failure@example.invalid')->value('invalidated_at'));
        Mail::assertNothingSent();
    }

    public function test_client_session_idle_absolute_expiry_and_revoke_are_enforced(): void
    {
        [, $flow, $challenge, $code] = $this->registration();
        $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1');
        $proof = $this->auth->complete($flow, 'Timeout fixture', 'new+tag@example.invalid', $this->binding, '192.0.2.1');
        $request = $this->requestSession();
        app(ClientSession::class)->issue($request, $proof);
        DB::table('sessions')->where('id', $request->session()->getId())->update(['last_activity' => time() - 43201]);
        $this->denied(fn () => app(ClientSession::class)->user($request), 'unauthenticated');
        DB::table('sessions')->where('id', $request->session()->getId())->update(['last_activity' => time()]);
        $state = DB::table('auth_session_states')->where('session_id', $request->session()->getId())->first();
        DB::statement('ALTER TABLE auth_session_states DISABLE TRIGGER auth2_session_transition');
        DB::table('auth_session_states')->where('session_id', $state->session_id)->update(['primary_verified_at' => now()->subDays(8), 'absolute_expires_at' => now()->subSecond()]);
        $this->denied(fn () => app(ClientSession::class)->user($request), 'unauthenticated');
        DB::table('auth_session_states')->where('session_id', $state->session_id)->update(['primary_verified_at' => $state->primary_verified_at, 'absolute_expires_at' => $state->absolute_expires_at]);
        DB::statement('ALTER TABLE auth_session_states ENABLE TRIGGER auth2_session_transition');
        $this->assertNotNull(app(ClientSession::class)->user($request));
        DB::table('auth_session_states')->where('session_id', $request->session()->getId())->update(['revoked_at' => app(ControlledAuth::class)->stamp()]);
        $this->denied(fn () => app(ClientSession::class)->user($request), 'unauthenticated');
    }

    public function test_invitation_actions_require_mfa_and_fresh_action_step_up(): void
    {
        DB::table('users')->where('id', $this->coach)->update(['email' => 'invite-admin@example.invalid', 'password' => Hash::make('synthetic-password')]);
        $secret = (new Google2FA)->generateSecretKey(32);
        DB::table('user_totp_factors')->insert(['user_id' => $this->coach, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret), 'key_version' => 'test_v1', 'confirmed_at' => now(), 'created_at' => now()->subMinute()]);
        $request = $this->requestSession();
        $request->session()->put('mfa_binding', $this->binding);
        $mfa = app(MfaService::class);
        $flow = $mfa->beginPasswordLogin('invite-admin@example.invalid', 'synthetic-password', $this->binding);
        if (time() % 30 >= 26) {
            usleep((31 - time() % 30) * 1000000);
        }
        $proof = $mfa->finishLogin($flow, $this->binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30) - 1));
        app(AdminAssurance::class)->issue($request, $proof);
        $service = app(InvitationService::class);
        try {
            $service->create($request, $this->coach, 'wrong-password', '000000');
            $this->fail('Expected step up denial');
        } catch (MfaDenied) {
            $this->addToAssertionCount(1);
        }
        $url = $service->create($request, $this->coach, 'synthetic-password', (new Google2FA)->oathTotp($secret, intdiv(time(), 30)))->reveal();
        $token = explode('#invite=', $url)[1];
        $invite = DB::table('client_invitations')->where('token_hash', hash('sha256', $token))->first();
        $this->assertNotNull($invite);
        $this->assertNull($invite->expires_at);
        $this->assertStringNotContainsString($token, json_encode($invite));
        $service->revoke($request, $invite->id, 'synthetic-password', (new Google2FA)->oathTotp($secret, intdiv(time(), 30) + 1));
        $this->denied(fn () => $this->auth->exchange($token, $this->binding, '192.0.2.1'), 'invitation_unavailable');
    }

    public function test_login_does_not_select_a_winner_of_historical_canonical_collision(): void
    {
        $user = DB::table('users')->insertGetId(['name' => 'Canonical', 'email' => 'collision@example.invalid', 'email_normalized' => 'collision@example.invalid', 'email_verified_at' => now()]);
        DB::table('clients')->insert(['user_id' => $user, 'coach_user_id' => $this->coach]);
        DB::table('users')->insert(['name' => 'Historical duplicate', 'email' => 'COLLISION@example.invalid']);
        $response = $this->auth->request('login', 'collision@example.invalid', null, $this->binding, '192.0.2.1');
        $this->assertFalse(DB::table('auth_challenges')->where('public_id', $response['challenge_id'])->exists());
        Mail::assertNothingSent();
    }

    public function test_previous_hmac_key_version_is_explicit_and_unknown_version_fails_closed(): void
    {
        [, , $challenge, $code] = $this->registration();
        config(['auth4.key_version' => 'test_v2', 'auth4.keys.test_v2' => 'synthetic-only-second-hmac-key-32minimum']);
        $this->assertSame('registration_complete', $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1')['next']);
        [, , $second, $secondCode] = $this->registration('key-version2@example.invalid');
        config(['auth4.key_version' => 'test_v1', 'auth4.keys.test_v2' => null]);
        $this->denied(fn () => $this->auth->verify($second, $secondCode, $this->binding, '192.0.2.1'), 'auth_unavailable');
        $this->assertNull(DB::table('auth_challenges')->where('public_id', $second)->value('consumed_at'));
    }

    public function test_client_mfa_failures_share_user_budget_across_new_flows(): void
    {
        $id = DB::table('users')->insertGetId(['name' => 'MFA limited', 'email' => 'mfa-limited@example.invalid', 'email_normalized' => 'mfa-limited@example.invalid', 'email_verified_at' => now()]);
        DB::table('clients')->insert(['user_id' => $id, 'coach_user_id' => $this->coach]);
        $secret = (new Google2FA)->generateSecretKey(32);
        DB::table('user_totp_factors')->insert(['user_id' => $id, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret), 'key_version' => 'test_v1', 'confirmed_at' => now(), 'created_at' => now()->subMinute()]);
        for ($round = 0; $round < 2; $round++) {
            Cache::store('database')->forget('auth4:'.app(ControlledAuth::class)->fingerprint('cooldown:login:mfa-limited@example.invalid'));
            [$challenge, $code] = $this->requestCode('login', 'mfa-limited@example.invalid');
            $flow = $this->auth->verify($challenge, $code, $this->binding, '192.0.2.1')['flow_id'];
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    app(MfaService::class)->finishLogin($flow, $this->binding, 'wrong');
                    $this->fail('Expected factor denial');
                } catch (MfaDenied) {
                    $this->addToAssertionCount(1);
                }
            }
            try {
                app(MfaService::class)->finishLogin($flow, $this->binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30)));
                $this->fail('Flow failure limit bypassed');
            } catch (MfaDenied) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue(Cache::store('database')->has('auth4:mfa-pause:'.$id));
        $this->assertSame(10, DB::table('auth_audit_events')->where('subject_user_id', $id)->where('event_type', 'mfa.denied')->count());
    }
}
