<?php

namespace Tests\Auth6B5;

use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\MfaService;
use App\Services\Auth\Notices\NoticeDispatcher;
use App\Services\Auth\Recovery\RecoveryBoundary;
use App\Services\Auth\Recovery\RecoveryService;
use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\RestoreFence;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Auth\Restore\SyntheticRestore;
use App\Services\Auth\Retention\RetentionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Auth6B\FixtureBoundary;
use Tests\TestCase;

final class RestoreTest extends TestCase
{
    private array $proofs = ['backup' => true, 'wal' => true, 'keys' => true, 'registry' => true, 'holds' => true, 'revocations' => true, 'audit' => true, 'hold_rows' => [], 'principal_revocations' => [], 'key_revocations' => []];

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::table('auth_security_runtime')->where('id', 1)->update(['restore_epoch' => SyntheticEpoch::INITIAL, 'state' => 'ready', 'issuance_not_before' => DB::raw('clock_timestamp()')]);
    }

    protected function tearDown(): void
    {
        Artisan::call('up');
        DB::table('auth_security_runtime')->where('id', 1)->update(['restore_epoch' => SyntheticEpoch::INITIAL, 'state' => 'ready', 'issuance_not_before' => DB::raw('clock_timestamp()')]);
        parent::tearDown();
    }

    private function denied(callable $call): void
    {
        try {
            $call();
            $this->fail('Restore opened authority');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function epoch(): SyntheticEpoch
    {
        $external = new SyntheticEpoch((string) Str::uuid());
        app()->instance(ExternalEpoch::class, $external);

        return $external;
    }

    private function fixture(): array
    {
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic restore', 'role' => 'client']);
        DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
            'confirmed_at' => DB::raw('clock_timestamp()'), 'last_used_step' => 999999999, 'created_at' => DB::raw("clock_timestamp()-interval '1 hour'")]);
        $code = DB::table('mfa_recovery_codes')->insertGetId(['user_id' => $user, 'selector' => (string) Str::uuid(),
            'batch_id' => (string) Str::uuid(), 'code_hash' => password_hash('synthetic', PASSWORD_ARGON2ID)]);
        $flow = DB::table('auth_flows')->insertGetId(['public_id' => (string) Str::uuid(), 'user_id' => $user, 'auth_version_at_start' => 0,
            'browser_binding_hash' => str_repeat('b', 64), 'audience' => 'client', 'purpose' => 'login', 'expires_at' => DB::raw("clock_timestamp()+interval '5 minutes'")]);
        $challenge = DB::table('auth_challenges')->insertGetId(['public_id' => (string) Str::uuid(), 'flow_id' => $flow, 'generation' => 1,
            'email_normalized' => 'synthetic@example.invalid', 'code_mac' => str_repeat('c', 64), 'key_version' => 'test_v1', 'max_attempts' => 5,
            'expires_at' => DB::raw("clock_timestamp()+interval '5 minutes'")]);
        $session = (string) Str::uuid();
        DB::table('sessions')->insert(['id' => $session, 'user_id' => $user, 'payload' => 'synthetic', 'last_activity' => time()]);
        DB::table('auth_session_states')->insert(['session_id' => $session, 'user_id' => $user, 'audience' => 'client', 'browser_binding_hash' => str_repeat('b', 64),
            'auth_version' => 0, 'primary_method' => 'email_otp', 'primary_verified_at' => DB::raw('clock_timestamp()'), 'absolute_expires_at' => DB::raw("clock_timestamp()+interval '7 days'")]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'synthetic-encrypted-snapshot', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        return compact('user', 'code', 'flow', 'challenge', 'session');
    }

    public function test_missing_stale_mismatched_and_unavailable_external_authority_close_operations(): void
    {
        app(RestoreFence::class)->assert();
        $this->assertTrue(true);
        $external = $this->epoch();
        $this->denied(fn () => app(RestoreFence::class)->assert());
        $external->epoch = null;
        $this->denied(fn () => app(RestoreFence::class)->assert());
        $external->epoch = 'not-an-epoch';
        $this->denied(fn () => app(RestoreFence::class)->assert());
        app()->forgetInstance(ExternalEpoch::class);
        app()->offsetUnset(ExternalEpoch::class);
        $this->denied(fn () => app(RestoreFence::class)->assert());
    }

    public function test_restore_revokes_snapshot_capabilities_preserves_watermark_and_never_opens_business_access(): void
    {
        $f = $this->fixture();
        $external = $this->epoch();
        Artisan::call('down');
        $started = hrtime(true);
        app(SyntheticRestore::class)->reconcile($external, $this->proofs);
        $this->assertGreaterThan(0, hrtime(true) - $started);
        $this->assertSame(1, DB::table('users')->where('id', $f['user'])->value('auth_version'));
        $this->assertNotNull(DB::table('mfa_recovery_codes')->where('id', $f['code'])->value('revoked_at'));
        $this->assertSame('invalidated', DB::table('auth_flows')->where('id', $f['flow'])->value('state'));
        $this->assertNotNull(DB::table('auth_challenges')->where('id', $f['challenge'])->value('invalidated_at'));
        $factor = DB::table('user_totp_factors')->where('user_id', $f['user'])->first();
        $this->assertSame(999999999, $factor->last_used_step);
        $this->assertNull($factor->secret_ciphertext);
        $this->assertFalse(MfaService::active($factor));
        $this->assertFalse(DB::table('sessions')->where('id', $f['session'])->exists());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('auth_security_notice_outbox')->whereIn('state', ['pending', 'leased'])->count());
        $this->assertTrue(DB::selectOne("SELECT issuance_not_before>=last_reconciled_at+interval '24 hours' b FROM auth_security_runtime")->b);
        $this->assertSame('recovery_required', DB::table('auth_security_runtime')->value('state'));
        $this->denied(fn () => app(RestoreFence::class)->assert());
        $before = DB::table('auth_security_runtime')->first();
        app(SyntheticRestore::class)->reconcile($external, $this->proofs);
        $this->assertEquals($before, DB::table('auth_security_runtime')->first());
        $this->assertSame(1, DB::table('users')->where('id', $f['user'])->value('auth_version'));
        // Навіть помилкова спроба owner позначити ready не обходить 24h issuance fence.
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        $this->denied(fn () => app(RestoreFence::class)->assert(issuance: true));
    }

    public function test_every_reconciliation_fault_leaves_closed_and_retry_does_not_reanimate_snapshot(): void
    {
        Artisan::call('down');
        foreach (['preflight', 'manifests', 'versions', 'capabilities', 'factors', 'delivery', 'reconciled', 'committed'] as $stage) {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
            $external = $this->epoch();
            $version = DB::table('users')->max('auth_version');
            $this->denied(fn () => app(SyntheticRestore::class)->reconcile($external, $this->proofs,
                function ($at) use ($stage) {
                    if ($at === $stage) {
                        throw new \RuntimeException('synthetic_crash');
                    }
                }));
            $this->denied(fn () => app(RestoreFence::class)->assert());
            $this->assertNotSame('ready', DB::table('auth_security_runtime')->value('state'));
            $this->assertSame($version + ($stage === 'committed' ? 1 : 0), DB::table('users')->max('auth_version'));
            app(SyntheticRestore::class)->reconcile($external, $this->proofs);
            $this->denied(fn () => app(RestoreFence::class)->assert());
        }
    }

    public function test_unproven_restore_and_nonmaintenance_invocation_remain_closed(): void
    {
        $external = $this->epoch();
        $this->denied(fn () => app(SyntheticRestore::class)->reconcile($external, $this->proofs));
        Artisan::call('down');
        foreach (array_keys($this->proofs) as $missing) {
            $proofs = $this->proofs;
            unset($proofs[$missing]);
            $this->denied(fn () => app(SyntheticRestore::class)->reconcile($external, $proofs));
            $this->assertSame('maintenance', DB::table('auth_security_runtime')->value('state'));
        }
        $this->denied(fn () => app(SyntheticRestore::class)->reconcile(new SyntheticEpoch, $this->proofs));
        $this->denied(fn () => app(RestoreFence::class)->assert());
    }

    public function test_external_rotation_during_reconciliation_rolls_back_and_workers_are_fenced(): void
    {
        $external = $this->epoch();
        Artisan::call('down');
        $version = DB::table('users')->max('auth_version');
        $this->denied(fn () => app(SyntheticRestore::class)->reconcile($external, $this->proofs,
            function ($stage) use ($external) {
                if ($stage === 'delivery') {
                    $external->epoch = (string) Str::uuid();
                }
            }));
        $this->assertSame($version, DB::table('users')->max('auth_version'));
        $this->assertSame('maintenance', DB::table('auth_security_runtime')->value('state'));
        $this->denied(fn () => app(ControlledAuth::class)->assert());
        $this->denied(fn () => app(NoticeDispatcher::class)->claim());
        $this->denied(fn () => app(RetentionService::class)->dryRun());
    }

    public function test_recovery_intent_epoch_must_match_current_runtime_epoch(): void
    {
        $boundary = new FixtureBoundary;
        $boundary->current['restore_epoch'] = (string) Str::uuid();
        app()->instance(RecoveryBoundary::class, $boundary);
        $before = DB::table('auth_recovery_cases')->count();
        $this->denied(fn () => app(RecoveryService::class)->create(9000000000, (string) Str::uuid(), (string) Str::uuid(), null, 'replace_mfa'));
        $this->assertSame($before, DB::table('auth_recovery_cases')->count());
    }
}
