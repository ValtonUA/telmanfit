<?php

namespace Tests\Auth3;

use App\Models\User;
use App\Services\Auth\AdminAssurance;
use App\Support\Auth\AuthSecretCodec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MfaTest extends MfaTestCase
{
    public function test_enrollment_confirmation_and_pending_browser_binding(): void
    {
        $flow = $this->flow();
        $secret = $this->mfa->beginEnrollment($flow, $this->binding);
        $this->assertSame('"[redacted]"', json_encode($secret));
        $this->assertStringNotContainsString($secret->reveal(), print_r($secret, true));
        $this->assertNull(DB::table('user_totp_factors')->where('user_id', $this->id)->value('confirmed_at'));
        $this->denied(fn () => $this->mfa->confirmEnrollment($flow, 'other-browser', $this->code($secret->reveal())));
        $this->denied(fn () => $this->mfa->confirmEnrollment($flow, $this->binding, 'invalid'));
        $codes = $this->mfa->confirmEnrollment($flow, $this->binding, $this->code($secret->reveal()));
        $this->assertCount(3, $codes->reveal());
        $this->assertSame(1, DB::table('users')->where('id', $this->id)->value('auth_version'));
        $this->denied(fn () => $this->mfa->confirmEnrollment($flow, $this->binding, $this->code($secret->reveal())));
        $stored = json_encode(DB::table('user_totp_factors')->where('user_id', $this->id)->first());
        $audit = json_encode(DB::table('auth_audit_events')->get());
        $this->assertStringNotContainsString($secret->reveal(), $stored.$audit);
        foreach ($codes->reveal() as $code) {
            $this->assertStringNotContainsString($code, json_encode(DB::table('mfa_recovery_codes')->get()).$audit);
        }
    }

    public function test_pending_is_bound_to_one_flow_and_expiry(): void
    {
        $one = $this->flow();
        $secret = $this->mfa->beginEnrollment($one, $this->binding)->reveal();
        $two = $this->flow();
        $this->denied(fn () => $this->mfa->confirmEnrollment($two, $this->binding, $this->code($secret)));
        $this->mfa->beginEnrollment($two, $this->binding);
        DB::table('user_totp_factors')->where('user_id', $this->id)->update(['pending_expires_at' => DB::raw("created_at + interval '1 microsecond'")]);
        $this->denied(fn () => $this->mfa->confirmEnrollment($two, $this->binding, $this->code($secret)));
    }

    public function test_wrong_expired_and_replayed_totp_and_cache_flush(): void
    {
        [$secret] = $this->enrolled();
        $flow = $this->flow();
        $this->denied(fn () => $this->mfa->finishLogin($flow, $this->binding, 'invalid'));
        $this->denied(fn () => $this->mfa->finishLogin($flow, $this->binding, $this->code($secret, -3)));
        $proof = $this->mfa->finishLogin($flow, $this->binding, $this->code($secret));
        $this->assertSame('totp', $proof->mfa_method);
        cache()->flush();
        $flow = $this->flow();
        $this->denied(fn () => $this->mfa->finishLogin($flow, $this->binding, $this->code($secret)));
    }

    public function test_recovery_single_use_version_bump_and_old_batch_rotation(): void
    {
        [$secret, $codes] = $this->enrolled();
        $proof = $this->mfa->finishLogin($this->flow(), $this->binding, $codes[0], true);
        $this->assertSame(2, $proof->auth_version);
        $this->assertSame('recovery_code', $proof->mfa_method);
        $this->denied(fn () => $this->mfa->finishLogin($this->flow(), $this->binding, $codes[0], true));
        $new = $this->mfa->rotateRecoveryCodes($this->flow(), $this->binding, $this->code($secret))->reveal();
        $this->denied(fn () => $this->mfa->finishLogin($this->flow(), $this->binding, $codes[1], true));
        $this->assertSame('recovery_code', $this->mfa->finishLogin($this->flow(), $this->binding, $new[0], true)->mfa_method);
    }

    public function test_replacement_needs_primary_and_old_factor_then_revokes_sessions(): void
    {
        [$secret, $codes] = $this->enrolled();
        $flow = $this->flow();
        $this->denied(fn () => $this->mfa->beginEnrollment($flow, $this->binding));
        $this->denied(fn () => $this->mfa->replaceFactor($flow, $this->binding, 'invalid'));
        $replacement = $this->mfa->replaceFactor($flow, $this->binding, $codes[0], true);
        $pending = $this->mfa->beginEnrollment($replacement, $this->binding)->reveal();
        $old = DB::table('user_totp_factors')->where('user_id', $this->id)->value('secret_ciphertext');
        $this->assertSame($secret, (new AuthSecretCodec)->decryptTotp($old));
        $new = $this->mfa->confirmEnrollment($replacement, $this->binding, $this->code($pending))->reveal();
        $this->assertNotEmpty($new);
        $this->assertSame($pending, (new AuthSecretCodec)->decryptTotp(DB::table('user_totp_factors')->where('user_id', $this->id)->value('secret_ciphertext')));
        $this->denied(fn () => $this->mfa->disableFactor($this->id));
        $this->denied(fn () => $this->mfa->finishLogin($this->flow(), $this->binding, $codes[1], true));
    }

    public function test_session_rotation_assurance_and_logout_all(): void
    {
        [$secret, $codes] = $this->enrolled();
        $proof = $this->mfa->finishLogin($this->flow(), $this->binding, $this->code($secret));
        $request = $this->request();
        $oldId = $request->session()->getId();
        $oldToken = $request->session()->token();
        $assurance = app(AdminAssurance::class);
        $assurance->issue($request, $proof);
        $this->denied(fn () => $assurance->issue($request, $proof));
        $this->assertNotSame($oldId, $request->session()->getId());
        $this->assertNotSame($oldToken, $request->session()->token());
        $this->assertFalse(DB::table('sessions')->where('id', $oldId)->exists());
        DB::transaction(fn () => $assurance->assert($request));
        $this->mfa->logoutAll($this->flow(), $this->binding, $codes[0], true);
        $this->denied(fn () => DB::transaction(fn () => $assurance->assert($request)));
        $this->assertNotNull(DB::table('auth_session_states')->where('user_id', $this->id)->value('revoked_at'));
    }

    public function test_password_only_and_wrong_guard_are_denied_on_http_resources_and_livewire(): void
    {
        $user = User::find($this->id);
        $this->actingAs($user, 'web');
        foreach (['/admin', '/admin/clients', '/admin/clients/1', '/admin/clients/1/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->actingAs($user, 'client');
        $this->get('/admin/clients')->assertForbidden();
        $this->actingAs($user, 'admin');
        $this->get('/admin/clients')->assertForbidden();
        $this->post('/admin/livewire/update', ['components' => []], ['X-Livewire' => 'true'])->assertForbidden();
        $this->post(route('default-livewire.update', absolute: false), ['components' => []], ['X-Livewire' => 'true'])->assertForbidden();
        $this->post('/livewire/update', ['components' => []], ['X-Livewire' => 'true'])->assertNotFound();
    }

    public function test_client_primary_contract_uses_the_same_durable_factor_without_password(): void
    {
        DB::table('users')->where('id', $this->id)->update(['role' => 'client', 'password' => null]);
        $makeFlow = function (): string {
            $id = (string) Str::uuid();
            DB::table('auth_flows')->insert(['public_id' => $id, 'user_id' => $this->id,
                'browser_binding_hash' => hash('sha256', $this->binding), 'audience' => 'client',
                'purpose' => 'login', 'auth_version_at_start' => DB::table('users')->where('id', $this->id)->value('auth_version'),
                'state' => 'primary_verified', 'created_at' => now()->subMinute(),
                'primary_verified_at' => now()->subSecond(), 'expires_at' => now()->addMinutes(5)]);

            return $id;
        };
        // Майбутній AUTH-4 створюватиме цей flow лише після durable email OTP consume.
        $flow = $makeFlow();
        $secret = $this->mfa->beginEnrollment($flow, $this->binding)->reveal();
        $this->mfa->confirmEnrollment($flow, $this->binding, $this->code($secret, -1));
        $proof = $this->mfa->finishLogin($makeFlow(), $this->binding, $this->code($secret));
        $this->assertSame('email_otp', $proof->primary_method);
        $this->assertSame('client', $proof->audience);
        $this->denied(fn () => app(AdminAssurance::class)->issue($this->request(), $proof));
        $this->denied(fn () => $this->mfa->finishLogin($makeFlow(), $this->binding, $this->code($secret)));
        $this->assertNull(DB::table('users')->where('id', $this->id)->value('password'));
    }

    public function test_expired_flow_and_key_version_mismatch_cannot_consume_factor(): void
    {
        [$secret] = $this->enrolled();
        $id = (string) Str::uuid();
        DB::table('auth_flows')->insert(['public_id' => $id, 'user_id' => $this->id,
            'browser_binding_hash' => hash('sha256', $this->binding), 'audience' => 'admin', 'purpose' => 'login',
            'auth_version_at_start' => 1, 'state' => 'primary_verified', 'created_at' => now()->subMinutes(5),
            'primary_verified_at' => now()->subMinutes(4), 'expires_at' => now()->subMinute()]);
        $this->denied(fn () => $this->mfa->finishLogin($id, $this->binding, $this->code($secret)));
        DB::table('user_totp_factors')->where('user_id', $this->id)->update(['key_version' => 'unknown']);
        $this->denied(fn () => $this->mfa->finishLogin($this->flow(), $this->binding, $this->code($secret)));
    }

    public function test_production_and_disabled_mode_cannot_start_new_auth(): void
    {
        $this->app->instance('env', 'production');
        $this->denied(fn () => $this->flow());
        $this->app->instance('env', 'testing');
        config(['mfa.enabled' => false]);
        $this->denied(fn () => $this->flow());
    }

    public function test_gate_outage_and_invalid_policy_fail_closed(): void
    {
        $this->actingAs(User::find($this->id), 'admin');
        $this->app->bind(AdminAssurance::class, fn () => throw new \RuntimeException('synthetic outage'));
        $this->get('/admin/clients')->assertStatus(503)->assertDontSee('synthetic outage');
        config(['mfa.session_seconds' => null]);
        $this->get('/admin/clients')->assertStatus(503);
    }

    public function test_bound_expired_revoked_wrong_audience_version_and_role_states_are_denied(): void
    {
        [$secret] = $this->enrolled();
        $request = $this->request();
        $proof = $this->mfa->finishLogin($this->flow(), $this->binding, $this->code($secret));
        $assurance = app(AdminAssurance::class);
        $assurance->issue($request, $proof);
        $originalBinding = $request->session()->get('mfa_binding');
        $originalId = $request->session()->getId();
        foreach (['binding', 'expiry', 'role', 'version', 'revoke', 'audience', 'session'] as $case) {
            DB::beginTransaction();
            if ($case === 'binding') {
                $request->session()->put('mfa_binding', 'wrong');
            }
            if ($case === 'expiry') {
                DB::table('auth_session_states')->where('user_id', $this->id)->update(['absolute_expires_at' => CarbonImmutable::parse($proof->mfa_verified_at)->addMicrosecond()->format('Y-m-d H:i:s.uP')]);
                usleep(1000);
            }
            if ($case === 'role') {
                DB::table('users')->where('id', $this->id)->update(['role' => 'coach']);
            }
            if ($case === 'version') {
                DB::table('users')->where('id', $this->id)->increment('auth_version');
            }
            if ($case === 'revoke') {
                DB::table('auth_session_states')->where('user_id', $this->id)->update(['revoked_at' => DB::raw('clock_timestamp()')]);
            }
            if ($case === 'audience') {
                $state = (array) DB::table('auth_session_states')->where('user_id', $this->id)->first();
                DB::table('auth_session_states')->where('user_id', $this->id)->delete();
                DB::table('auth_session_states')->insert(array_replace($state, ['audience' => 'client', 'primary_method' => 'email_otp']));
            }
            if ($case === 'session') {
                $request->session()->setId(str_repeat('x', 40));
            }
            $this->denied(fn () => $assurance->assert($request));
            DB::rollBack();
            $request->session()->setId($originalId);
            $request->session()->put('mfa_binding', $originalBinding);
        }
    }
}
