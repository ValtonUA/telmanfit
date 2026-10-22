<?php

namespace Tests\Auth5;

use App\Http\Controllers\Api\ClientAuthController;
use App\Mail\EmailOtp;
use App\Mail\SecurityNotice;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSecurity;
use App\Services\Auth\Client\ClientSession;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use App\Services\Auth\Notices\NoticeUnavailable;
use App\Services\Auth\Notices\RecipientCodec;
use App\Support\Auth\AuthSecretCodec;
use App\Support\Session\ContextDatabaseSessionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ClientSecurityTest extends TestCase
{
    private ClientSecurity $security;

    private Request $request;

    private int $id;

    private int $coach = 8500000000;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        DB::table('users')->insert(['id' => $this->coach, 'name' => 'AUTH5 coach', 'role' => 'admin']);
        $this->id = DB::table('users')->insertGetId(['name' => 'AUTH5 клієнт', 'email' => 'auth5@example.invalid',
            'email_normalized' => 'auth5@example.invalid', 'email_verified_at' => now()]);
        DB::table('clients')->insert(['user_id' => $this->id, 'coach_user_id' => $this->coach]);
        Mail::fake();
        $this->security = app(ClientSecurity::class);
        $this->request = Request::create('/api/auth/security/sessions', 'POST', server: ['REMOTE_ADDR' => '192.0.2.55']);
        $this->request->setLaravelSession(app('session')->driver());
        $this->request->session()->setName('telmanfit_client_session');
        $this->request->session()->start();
        $this->request->session()->put('client_binding', bin2hex(random_bytes(32)));
        $this->request->session()->save();
        app()->instance('request', $this->request);
        $this->login();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function binding(): string
    {
        return $this->request->session()->get('client_binding');
    }

    private function otp(): string
    {
        preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);

        return $match[1];
    }

    private function allowSend(string $purpose, string $email = 'auth5@example.invalid'): void
    {
        // Лише синтетичне моделювання плину cooldown; aggregate budgets зберігаються.
        Cache::store('database')->forget('auth4:'.app(ControlledAuth::class)->fingerprint('cooldown:'.$purpose.':'.$email));
    }

    private function login(?string $recovery = null, ?string $secret = null): void
    {
        if (! $this->request->session()->has('client_binding')) {
            $this->request->session()->put('client_binding', bin2hex(random_bytes(32)));
        }
        $this->allowSend('login');
        $auth = app(ClientAuth::class);
        $email = DB::table('users')->where('id', $this->id)->value('email');
        $result = $auth->request('login', $email, null, $this->binding(), '192.0.2.55');
        $result = $auth->verify($result['challenge_id'], $this->otp(), $this->binding(), '192.0.2.55');
        if ($result['next'] === 'mfa_required') {
            $proof = app(MfaService::class)->finishLogin($result['flow_id'], $this->binding(), $recovery ?? $this->totp($secret), $recovery !== null);
        } else {
            $proof = $result['proof'];
        }
        app(ClientSession::class)->issue($this->request, $proof);
    }

    private function totp(string $secret, int $offset = 0): string
    {
        if ($offset === -1 && time() % 30 >= 26) {
            usleep((31 - time() % 30) * 1000000);
        }

        return (new Google2FA)->oathTotp($secret, intdiv(time(), 30) + $offset);
    }

    private function grant(string $action, ?string $code = null, bool $recovery = false): string
    {
        $this->allowSend('reauth');
        $result = $this->security->requestReauth($this->request, $action);
        $result = $this->security->verifyReauth($this->request, $result['challenge_id'], $this->otp());
        if ($result['next'] === 'mfa_required') {
            $result = $this->security->authorize($this->request, $result['flow_id'], $code ?? 'wrong', $recovery);
        }
        $this->assertSame('authorized', $result['next']);

        return $result['flow_id'];
    }

    private function enrolled(): array
    {
        $data = $this->security->enrollment($this->request, $this->grant('enable_factor'), false);
        $secret = $data['secret']->reveal();
        $codes = $this->security->confirmEnrollment($this->request, $data['flow_id'], $this->totp($secret, -1))->reveal();

        return [$secret, $codes];
    }

    private function denied(callable $operation, ?string $reason = null): void
    {
        try {
            $operation();
            $this->fail('Expected security denial');
        } catch (AuthDenied $e) {
            if ($reason) {
                $this->assertSame($reason, $e->reason);
            } else {
                $this->addToAssertionCount(1);
            }
        } catch (MfaDenied) {
            $this->assertNull($reason);
        }
    }

    public function test_enrollment_requires_fresh_proof_and_revokes_sessions_with_one_time_codes(): void
    {
        $this->denied(fn () => $this->security->enrollment($this->request, (string) Str::uuid(), false));
        $before = $this->request->session()->getId();
        $absolute = DB::table('auth_session_states')->where('session_id', $before)->value('absolute_expires_at');
        $proof = $this->grant('enable_factor');
        $this->assertNotSame($before, $this->request->session()->getId());
        $this->assertSame($absolute, DB::table('auth_session_states')->where('session_id', $this->request->session()->getId())->value('absolute_expires_at'));
        $data = $this->security->enrollment($this->request, $proof, false);
        $this->assertStringNotContainsString($data['secret']->reveal(), json_encode($data));
        $this->assertNull(DB::table('user_totp_factors')->where('user_id', $this->id)->value('confirmed_at'));
        $this->denied(fn () => $this->security->enrollment($this->request, $proof, false));
        $codes = $this->security->confirmEnrollment($this->request, $data['flow_id'], $this->totp($data['secret']->reveal(), -1))->reveal();
        $this->assertCount(3, $codes);
        $this->assertSame(1, DB::table('users')->where('id', $this->id)->value('auth_version'));
        $this->denied(fn () => app(ClientSession::class)->user($this->request), 'unauthenticated');
        $this->login($codes[0]);
        $this->assertSame($this->id, app(ClientSession::class)->user($this->request)->id);
        $this->assertSame(2, DB::table('users')->where('id', $this->id)->value('auth_version'));
        $this->assertNotNull(DB::table('mfa_recovery_codes')->where('selector', explode('.', $codes[0])[0])->value('used_at'));
    }

    public function test_email_otp_alone_cannot_disable_active_mfa(): void
    {
        [$secret, $codes] = $this->enrolled();
        $this->login($codes[0]);
        $this->allowSend('reauth');
        $result = $this->security->requestReauth($this->request, 'disable_factor');
        $verified = $this->security->verifyReauth($this->request, $result['challenge_id'], $this->otp());
        $this->assertSame('mfa_required', $verified['next']);
        $this->denied(fn () => $this->security->disable($this->request, $verified['flow_id']));
        $this->denied(fn () => $this->security->authorize($this->request, $verified['flow_id'], '', false));
        $this->assertNotNull(DB::table('user_totp_factors')->where('user_id', $this->id)->value('confirmed_at'));
        $granted = $this->security->authorize($this->request, $verified['flow_id'], $this->totp($secret), false);
        $watermark = DB::table('user_totp_factors')->where('user_id', $this->id)->value('last_used_step');
        $this->security->disable($this->request, $granted['flow_id']);
        $disabled = DB::table('user_totp_factors')->where('user_id', $this->id)->first();
        $this->assertNotNull($disabled->disabled_at);
        $this->assertNull($disabled->secret_ciphertext);
        $this->assertSame($watermark, $disabled->last_used_step);
        $this->assertTrue(DB::table('user_totp_factors')->where('user_id', $this->id)->exists());
        $this->assertSame(3, DB::table('mfa_recovery_codes')->where('user_id', $this->id)->count());
    }

    public function test_recovery_rotation_and_factor_replacement_preserve_active_secret_until_confirmed(): void
    {
        [$secret, $codes] = $this->enrolled();
        $this->login($codes[0]);
        $newCodes = $this->security->rotateRecovery($this->request, $this->grant('rotate_recovery', $codes[1], true))->reveal();
        $this->assertCount(3, $newCodes);
        $this->assertNotNull(DB::table('mfa_recovery_codes')->where('selector', explode('.', $codes[2])[0])->value('revoked_at'));
        $this->login($newCodes[0]);
        $data = $this->security->enrollment($this->request, $this->grant('replace_factor', $newCodes[1], true), true);
        $factor = DB::table('user_totp_factors')->where('user_id', $this->id)->first();
        $this->assertSame($secret, app(AuthSecretCodec::class)->decryptTotp($factor->secret_ciphertext));
        $this->assertNotSame($secret, $data['secret']->reveal());
        $finalCodes = $this->security->confirmEnrollment($this->request, $data['flow_id'], $this->totp($data['secret']->reveal(), 1))->reveal();
        $this->assertCount(3, $finalCodes);
        $this->assertSame($data['secret']->reveal(), app(AuthSecretCodec::class)->decryptTotp(DB::table('user_totp_factors')->where('user_id', $this->id)->value('secret_ciphertext')));
    }

    public function test_disabled_factor_cannot_authenticate_and_reenrollment_preserves_watermark(): void
    {
        [$oldSecret, $oldCodes] = $this->enrolled();
        $this->login($oldCodes[0]);
        $proof = $this->grant('disable_factor', $oldCodes[1], true);
        $watermark = DB::table('user_totp_factors')->where('user_id', $this->id)->value('last_used_step');
        $this->security->disable($this->request, $proof);
        $disabled = DB::table('user_totp_factors')->where('user_id', $this->id)->first();
        $this->assertFalse(MfaService::active($disabled));
        $this->assertNull($disabled->secret_ciphertext);
        $this->assertNull($disabled->recovery_batch_id);
        $this->assertSame($watermark, $disabled->last_used_step);
        $this->assertSame(3, DB::table('mfa_recovery_codes')->where('user_id', $this->id)->count());
        $this->login();
        $data = $this->security->enrollment($this->request, $this->grant('enable_factor'), false);
        $this->assertNotSame($oldSecret, $data['secret']->reveal());
        $this->assertNotNull(DB::table('user_totp_factors')->where('user_id', $this->id)->value('disabled_at'));
        $this->security->confirmEnrollment($this->request, $data['flow_id'], $this->totp($data['secret']->reveal(), 1));
        $active = DB::table('user_totp_factors')->where('user_id', $this->id)->first();
        $this->assertTrue(MfaService::active($active));
        $this->assertGreaterThan($watermark, $active->last_used_step);
        $this->denied(fn () => $this->login($oldCodes[2]));
    }

    public function test_disabled_factor_sql_checks_prevent_secret_resurrection_and_watermark_reset(): void
    {
        [, $codes] = $this->enrolled();
        $this->login($codes[0]);
        $this->security->disable($this->request, $this->grant('disable_factor', $codes[1], true));
        foreach ([['disabled_at' => null], ['last_used_step' => null], ['last_used_step' => 0], ['secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp('SYNTHETICOLD'), 'key_version' => 'test_v1']] as $changes) {
            try {
                DB::transaction(fn () => DB::table('user_totp_factors')->where('user_id', $this->id)->update($changes));
                $this->fail('SQL invariant bypassed');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        try {
            (require database_path('migrations/2026_10_22_001000_add_disabled_at_to_user_totp_factors.php'))->down();
            $this->fail('Populated rollback accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Відкат скасовано', $e->getMessage());
        }
    }

    public function test_recovery_reauth_consumption_and_session_revocation_are_one_transaction(): void
    {
        [, $codes] = $this->enrolled();
        $this->login($codes[0]);
        $this->allowSend('reauth');
        $result = $this->security->requestReauth($this->request, 'logout_all');
        $result = $this->security->verifyReauth($this->request, $result['challenge_id'], $this->otp());
        $version = DB::table('users')->where('id', $this->id)->value('auth_version');
        $session = $this->request->session()->getId();
        DB::unprepared("CREATE FUNCTION auth5_session_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic session failure'; END $$;
            CREATE TRIGGER auth5_session_fault BEFORE INSERT ON auth_session_states FOR EACH ROW EXECUTE FUNCTION auth5_session_fault();");
        try {
            $this->security->authorize($this->request, $result['flow_id'], $codes[1], true);
            $this->fail('Session fault accepted');
        } catch (QueryException) {
            $this->assertNull(DB::table('mfa_recovery_codes')->where('selector', explode('.', $codes[1])[0])->value('used_at'));
            $this->assertSame($version, DB::table('users')->where('id', $this->id)->value('auth_version'));
            $this->assertNull(DB::table('auth_session_states')->where('session_id', $session)->value('revoked_at'));
            $this->assertTrue(DB::table('sessions')->where('id', $session)->exists());
        }
    }

    public function test_final_shared_mfa_operation_rechecks_revoked_session_under_user_lock(): void
    {
        $data = $this->security->enrollment($this->request, $this->grant('enable_factor'), false);
        DB::table('auth_session_states')->where('session_id', $this->request->session()->getId())->update(['revoked_at' => app(ControlledAuth::class)->stamp()]);
        $this->denied(fn () => app(MfaService::class)->confirmEnrollment($data['flow_id'], $this->binding(), $this->totp($data['secret']->reveal())));
        $this->assertNull(DB::table('user_totp_factors')->where('user_id', $this->id)->value('confirmed_at'));
    }

    public function test_action_proof_rejects_wrong_action_other_session_version_expiry_and_replay(): void
    {
        $proof = $this->grant('enable_factor');
        $this->denied(fn () => $this->security->logoutAll($this->request, $proof), 'reauth_required');
        $session = $this->request->session()->getId();
        $this->request->session()->setId(str_repeat('x', 40));
        $this->denied(fn () => $this->security->enrollment($this->request, $proof, false), 'unauthenticated');
        $this->request->session()->setId($session);
        DB::statement('ALTER TABLE auth_flows DISABLE TRIGGER auth2_flow_transition');
        DB::table('auth_flows')->where('public_id', $proof)->update(['created_at' => now()->subMinutes(10), 'primary_verified_at' => now()->subMinutes(6), 'expires_at' => now()->subMinute()]);
        DB::statement('ALTER TABLE auth_flows ENABLE TRIGGER auth2_flow_transition');
        $this->denied(fn () => $this->security->enrollment($this->request, $proof, false), 'reauth_required');
    }

    public function test_session_handles_are_opaque_and_logout_all_revokes_parallel_sessions(): void
    {
        $state = (array) DB::table('auth_session_states')->where('session_id', $this->request->session()->getId())->first();
        DB::table('sessions')->insert(['id' => str_repeat('z', 40), 'payload' => '', 'last_activity' => time()]);
        $state['session_id'] = str_repeat('z', 40);
        DB::table('auth_session_states')->insert($state);
        $foreign = DB::table('users')->insertGetId(['name' => 'Інший клієнт', 'email' => 'foreign-auth5@example.invalid',
            'email_normalized' => 'foreign-auth5@example.invalid', 'email_verified_at' => now()]);
        DB::table('clients')->insert(['user_id' => $foreign, 'coach_user_id' => $this->coach]);
        DB::table('sessions')->insert(['id' => str_repeat('y', 40), 'payload' => '', 'last_activity' => time()]);
        DB::table('auth_session_states')->insert(array_replace($state, ['session_id' => str_repeat('y', 40), 'user_id' => $foreign]));
        $rows = $this->security->sessions($this->request);
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString($state['session_id'], json_encode($rows));
        $target = array_values(array_filter($rows, fn ($row) => ! $row['current']))[0];
        $proof = $this->grant('revoke_session');
        $foreignHandle = app(ControlledAuth::class)->fingerprint('session:'.$foreign.':0:'.str_repeat('y', 40));
        $this->denied(fn () => $this->security->revokeSession($this->request, $proof, $foreignHandle), 'request_denied');
        // Після rollback session-memory proof не відновлюється; потрібна нова явна дія.
        $this->security->revokeSession($this->request, $this->grant('revoke_session'), $target['handle']);
        $this->assertCount(1, $this->security->sessions($this->request));
        $this->security->logoutAll($this->request, $this->grant('logout_all'));
        $this->assertSame(0, DB::table('auth_session_states')->where('user_id', $this->id)->whereNull('revoked_at')->count());
        $this->assertNull(DB::table('auth_session_states')->where('session_id', str_repeat('y', 40))->value('revoked_at'));
        $this->denied(fn () => app(ClientSession::class)->user($this->request), 'unauthenticated');
    }

    public function test_email_change_verifies_new_address_then_revokes_without_role_or_profile_changes(): void
    {
        $client = DB::table('clients')->where('user_id', $this->id)->first();
        $proof = $this->grant('email_change');
        $result = $this->security->requestEmail($this->request, $proof, 'New.Auth5@Example.invalid');
        $this->assertSame('auth5@example.invalid', DB::table('users')->where('id', $this->id)->value('email'));
        $this->denied(fn () => $this->security->confirmEmail($this->request, $result['challenge_id'], 'wrong'));
        $this->security->confirmEmail($this->request, $result['challenge_id'], $this->otp());
        $user = DB::table('users')->where('id', $this->id)->first();
        $this->assertSame('new.auth5@example.invalid', $user->email_normalized);
        $this->assertSame('client', $user->role);
        $notice = DB::table('auth_security_notice_outbox')->where('subject_user_id', $this->id)->where('notice_type', 'email_changed')->first();
        $this->assertSame('old', $notice->recipient_slot);
        $this->assertSame('auth5@example.invalid', app(RecipientCodec::class)->decrypt($notice->recipient_ciphertext, $notice->recipient_key_version));
        Mail::assertNotSent(SecurityNotice::class);
        $this->assertEquals($client, DB::table('clients')->where('user_id', $this->id)->first());
        $this->denied(fn () => app(ClientSession::class)->user($this->request), 'unauthenticated');
    }

    public function test_profile_exposes_only_remaining_recovery_count_and_active_disabled_state(): void
    {
        [, $codes] = $this->enrolled();
        $this->login($codes[0]);
        $route = new Route('GET', 'api/me', ClientAuthController::class);
        $route->name('auth4.me');
        $this->request->setRouteResolver(fn () => $route);
        $profile = fn () => app(ClientAuthController::class)($this->request, app(ClientAuth::class), app(ClientSession::class), app(ControlledAuth::class), app(MfaService::class))->getData(true);
        $data = $profile();
        $this->assertSame(2, $data['security']['recovery_codes_remaining']);
        $this->assertTrue($data['security']['recovery_used']);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, json_encode($data));
        }
        $this->security->disable($this->request, $this->grant('disable_factor', $codes[1], true));
        $this->login();
        $data = $profile();
        $this->assertFalse($data['security']['mfa_enabled']);
        $this->assertFalse($data['security']['recovery_used']);
        $this->assertSame(0, $data['security']['recovery_codes_remaining']);
    }

    public function test_email_conflict_never_merges_or_changes_another_user(): void
    {
        DB::table('users')->insert(['name' => 'Historical', 'email' => 'OCCUPIED@example.invalid']);
        $result = $this->security->requestEmail($this->request, $this->grant('email_change'), 'occupied@example.invalid');
        $this->denied(fn () => $this->security->confirmEmail($this->request, $result['challenge_id'], $this->otp()), 'email_conflict');
        $this->assertSame('auth5@example.invalid', DB::table('users')->where('id', $this->id)->value('email'));
        $this->assertNull(DB::table('users')->where('name', 'Historical')->value('email_verified_at'));
    }

    public function test_email_change_audit_fault_rolls_back_identity_version_and_sessions(): void
    {
        $result = $this->security->requestEmail($this->request, $this->grant('email_change'), 'audit-rollback@example.invalid');
        DB::unprepared("CREATE FUNCTION auth5_audit_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='client.email_changed' THEN RAISE EXCEPTION 'synthetic audit failure'; END IF; RETURN NEW; END $$;
            CREATE TRIGGER auth5_audit_fault BEFORE INSERT ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION auth5_audit_fault();");
        try {
            $this->security->confirmEmail($this->request, $result['challenge_id'], $this->otp());
            $this->fail('Audit failure did not abort identity mutation');
        } catch (QueryException) {
            $user = app(ClientSession::class)->user($this->request);
            $this->assertSame('auth5@example.invalid', $user->email_normalized);
            $this->assertSame(0, $user->auth_version);
        }
    }

    public function test_admin_role_cannot_use_client_factor_disable(): void
    {
        [$secret, $codes] = $this->enrolled();
        $this->login($codes[0]);
        $proof = $this->grant('disable_factor', $this->totp($secret));
        DB::table('users')->where('id', $this->id)->update(['role' => 'admin']);
        $this->denied(fn () => $this->security->disable($this->request, $proof));
        $this->assertNotNull(DB::table('user_totp_factors')->where('user_id', $this->id)->value('confirmed_at'));
        $this->denied(fn () => app(ClientSession::class)->user($this->request), 'unauthenticated');
    }

    public function test_http_default_off_validation_and_store_outage_fail_closed(): void
    {
        config(['auth5.enabled' => false]);
        $this->getJson('/api/auth/security/sessions')->assertNotFound();
        config(['auth5.enabled' => 'misconfigured']);
        $this->getJson('/api/auth/security/sessions')->assertNotFound();
        config(['auth5.enabled' => true]);
        $this->postJson('/api/auth/security/reauth/request', ['action' => 'admin_reset'])->assertUnprocessable();
        config(['mail.default' => 'smtp']);
        $this->getJson('/api/auth/security/sessions')->assertStatus(503);
        config(['mail.default' => 'array']);
        DB::statement('ALTER TABLE cache RENAME TO auth5_cache_outage');
        $this->postJson('/api/auth/email-otp/request', ['purpose' => 'login', 'email' => 'auth5@example.invalid'])->assertStatus(503);
    }

    public function test_email_notice_is_durable_without_queue_dispatch(): void
    {
        $result = $this->security->requestEmail($this->request, $this->grant('email_change'), 'notice6@example.invalid');
        Bus::shouldReceive('dispatch')->never();
        $this->security->confirmEmail($this->request, $result['challenge_id'], $this->otp());
        $changed = DB::table('auth_audit_events')->where('subject_user_id', $this->id)->where('event_type', 'client.email_changed')->first();
        $notice = DB::table('auth_security_notice_outbox')->where('request_id', $changed->request_id)->first();
        $this->assertSame('pending', $notice->state);
        $this->assertSame('notice6@example.invalid', DB::table('users')->where('id', $this->id)->value('email_normalized'));
    }

    public function test_notice_audit_failure_rolls_back_email_and_revocation(): void
    {
        $result = $this->security->requestEmail($this->request, $this->grant('email_change'), 'notice-outage6@example.invalid');
        DB::unprepared("CREATE FUNCTION auth6_notice_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='notice.queued' THEN RAISE EXCEPTION 'CANARY-secret'; END IF; RETURN NEW; END $$;
            CREATE TRIGGER auth6_notice_fault BEFORE INSERT ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION auth6_notice_fault();");
        try {
            $this->security->confirmEmail($this->request, $result['challenge_id'], $this->otp());
            $this->fail('Mandatory notice failure ignored');
        } catch (NoticeUnavailable $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('CANARY', (string) $e);
        }
        $this->assertSame('auth5@example.invalid', DB::table('users')->where('id', $this->id)->value('email_normalized'));
        $this->assertSame(0, DB::table('users')->where('id', $this->id)->value('auth_version'));
    }

    public function test_admin_gc_keeps_real_client_assurance_past_admin_idle_then_expires_at_client_idle(): void
    {
        $this->request->session()->save();
        $id = $this->request->session()->getId();
        DB::table('sessions')->where('id', $id)->update(['last_activity' => time() - 960]);
        $handler = new ContextDatabaseSessionHandler(DB::connection(), 'sessions', 15, app());
        $handler->gc(900);
        $this->assertSame($this->id, app(ClientSession::class)->user($this->request)->id);
        DB::table('sessions')->where('id', $id)->update(['last_activity' => time() - 43201]);
        $handler->gc(900);
        $this->assertFalse(DB::table('sessions')->where('id', $id)->exists());
        $this->assertFalse(DB::table('auth_session_states')->where('session_id', $id)->exists());
        $this->denied(fn () => app(ClientSession::class)->user($this->request), 'unauthenticated');
    }
}
