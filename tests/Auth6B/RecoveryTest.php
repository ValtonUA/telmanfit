<?php

namespace Tests\Auth6B;

use App\Mail\EmailOtp;
use App\Models\User;
use App\Services\Auth\Client\AuthBudget;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\Client\EmailNormalizer;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use App\Services\Auth\Notices\NoticeUnavailable;
use App\Services\Auth\Recovery\RecoveryBoundary;
use App\Services\Auth\Recovery\RecoveryDenied;
use App\Services\Auth\Recovery\RecoveryGate;
use App\Services\Auth\Recovery\RecoveryService;
use App\Services\Auth\Recovery\RecoverySignatures;
use App\Support\Auth\AuthSecretCodec;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class RecoveryTest extends TestCase
{
    private RecoveryService $recovery;

    private FixtureBoundary $boundary;

    private array $keys = [];

    private string $target;

    private string $initiator;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        $this->boundary = new FixtureBoundary;
        app()->instance(RecoveryBoundary::class, $this->boundary);
        $this->recovery = app(RecoveryService::class);
        app()->instance(RecoveryService::class, $this->recovery);
        $this->user = random_int(9000000000, 9999999999);
        Mail::fake();
    }

    private function principal(string $authority, ?int $user = null): string
    {
        $id = (string) Str::uuid();
        DB::table('auth_recovery_principals')->insert(['id' => $id, 'linked_user_id' => $user, 'authority' => $authority,
            'identity_record_digest' => hash('sha256', $id), 'created_at' => now()->subDays(10)]);

        return $id;
    }

    private function signer(string $authority, ?int $user = null, ?string $existing = null): array
    {
        $principal = $existing ?? $this->principal($authority, $user);
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $key = (string) Str::uuid();
        DB::table('auth_recovery_keys')->insert(['id' => $key, 'principal_id' => $principal,
            'public_key' => DB::raw("decode('".bin2hex($public)."','hex')"), 'fingerprint' => hash('sha256', $public),
            'registry_version' => 1, 'valid_from' => now()->subDays(2), 'valid_until' => now()->addDays(7)]);

        return ['principal_id' => $principal, 'key_id' => $key, 'secret' => sodium_crypto_sign_secretkey($pair)];
    }

    private function fixture(string $role = 'admin', bool $lostPrimary = false, bool $password = false, bool $elapsed = true): object
    {
        DB::table('users')->insert(['id' => $this->user, 'name' => 'Synthetic B3', 'role' => $role,
            'email' => $this->user.'@example.invalid', 'email_normalized' => $this->user.'@example.invalid',
            'email_verified_at' => now(), 'password' => $role === 'admin' ? Hash::make('old-synthetic-password') : null]);
        if ($role === 'client') {
            $coach = $this->user + 10000000000;
            DB::table('users')->insert(['id' => $coach, 'name' => 'Synthetic coach', 'role' => 'admin']);
            DB::table('clients')->insert(['user_id' => $this->user, 'coach_user_id' => $coach, 'status' => 'pending']);
            $this->keys = [$this->signer('admin_approver', $coach), $this->signer('reviewer')];
        } else {
            $this->keys = [$this->signer('trustee'), $this->signer('trustee')];
        }
        $this->target = $this->principal('operator', $this->user);
        $this->initiator = $this->principal('operator');
        $case = $this->recovery->create($this->user, $this->target, $this->initiator, $this->initiator,
            $lostPrimary ? 'replace_primary_mfa' : 'replace_mfa', $password, $lostPrimary ? 'new'.$this->user.'@example.invalid' : null);
        if ($elapsed) {
            // Fixture owner моделює час до intake; production constraints не змінюються.
            // Case immutable: створюємо intent одразу з минулими timestamps через новий row.
            $this->recovery->close($case->id, 'cancelled');
            $row = (array) $case;
            $row['id'] = (string) Str::uuid();
            $row['intent_nonce'] = (string) Str::uuid();
            $clock = now();
            $row['created_at'] = $clock->copy()->subHours(25)->format('Y-m-d H:i:s.uP');
            $row['expires_at'] = $clock->copy()->addHours(47)->format('Y-m-d H:i:s.uP');
            $row['intent_digest'] = hash('sha256', app(RecoverySignatures::class)->intent((object) $row));
            DB::table('auth_recovery_cases')->insert($row);
            $case = (object) $row;
        }

        return $case;
    }

    private function approval(object $case, array $key): array
    {
        $approval = ['principal_id' => $key['principal_id'], 'key_id' => $key['key_id'],
            'evidence_digest' => hash('sha256', 'synthetic-evidence'), 'verification_receipt_id' => (string) Str::uuid(),
            'expires_at' => now()->addHours(CarbonImmutable::parse($case->created_at)->lessThan(now()->subHour()) ? 23 : 47)->format('Y-m-d H:i:s.uP')];
        $approval['signature'] = base64_encode(sodium_crypto_sign_detached(app(RecoverySignatures::class)->envelope($case, $approval), $key['secret']));

        return $approval;
    }

    private function ready(object $case, bool $issue = true): string
    {
        // Підписані approvals існували 25h; hold минув. Без fake PG clock/TTL override.
        foreach ($this->keys as $key) {
            $a = $this->approval($case, $key);
            DB::table('auth_recovery_approvals')->insert(['case_id' => $case->id, 'principal_id' => $a['principal_id'], 'key_id' => $a['key_id'],
                'intent_digest' => $case->intent_digest, 'signature' => DB::raw("decode('".bin2hex(base64_decode($a['signature']))."','hex')"),
                'evidence_digest' => $a['evidence_digest'], 'verification_receipt_id' => $a['verification_receipt_id'],
                'verified_at' => now()->subHours(24)->subMinutes(59), 'expires_at' => $a['expires_at']]);
        }
        DB::table('auth_recovery_cases')->where('id', $case->id)->update(['state' => 'hold', 'hold_until' => now()->subMinutes(1)]);

        return $issue ? $this->recovery->issue($case->id)->reveal() : '';
    }

    private function reject(callable $action): void
    {
        try {
            DB::transaction($action);
            $this->fail('Expected security denial');
        } catch (RecoveryDenied|MfaDenied|QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public static function scenarios(): array
    {
        return ['client lost MFA with email' => ['client', false, false], 'other admin' => ['admin', false, false],
            'last admin all factors' => ['admin', true, true], 'lost email TOTP codes' => ['client', true, false]];
    }

    #[DataProvider('scenarios')]
    public function test_recovery_requires_new_primary_totp_and_full_login(string $role, bool $lostPrimary, bool $password): void
    {
        // Лише synthetic fixture: останній admin має бути єдиним у БД на час сценарію.
        $otherAdmins = $password ? DB::table('users')->where('role', 'admin')->pluck('id')->all() : [];
        if ($otherAdmins) {
            DB::table('users')->whereIn('id', $otherAdmins)->update(['role' => 'coach']);
        }
        try {
            $case = $this->fixture($role, $lostPrimary, $password);
            if ($password) {
                $this->assertSame(1, DB::table('users')->where('role', 'admin')->count());
            } elseif ($role === 'admin') {
                $reviewer = $this->user + 20000000000;
                DB::table('users')->insert(['id' => $reviewer, 'name' => 'Synthetic other admin', 'role' => 'admin']);
                $this->keys[0] = $this->signer('admin_approver', $reviewer);
            }
            $oldSecret = (new Google2FA)->generateSecretKey(32);
            $codec = app(AuthSecretCodec::class);
            DB::table('user_totp_factors')->insert(['user_id' => $this->user, 'secret_ciphertext' => $codec->encryptTotp($oldSecret),
                'key_version' => 'test_v1', 'confirmed_at' => now()->subHour(), 'created_at' => now()->subHours(2), 'last_used_step' => intdiv(time(), 30) - 2]);
            $oldSelector = (string) Str::uuid();
            $oldRecoveryCode = bin2hex(random_bytes(20));
            DB::table('mfa_recovery_codes')->insert(['user_id' => $this->user, 'selector' => $oldSelector, 'batch_id' => (string) Str::uuid(),
                'code_hash' => $codec->hashRecoveryCode($oldRecoveryCode)]);
            $oldFlow = (string) Str::uuid();
            $oldBinding = bin2hex(random_bytes(32));
            DB::table('auth_flows')->insert(['public_id' => $oldFlow, 'browser_binding_hash' => hash('sha256', $oldBinding), 'audience' => $role,
                'purpose' => 'login', 'user_id' => $this->user, 'auth_version_at_start' => 0, 'state' => 'primary_verified',
                'created_at' => now()->subMinute(), 'primary_verified_at' => now(), 'expires_at' => now()->addMinutes(5)]);
            $oldSession = Str::random(40);
            DB::table('sessions')->insert(['id' => $oldSession, 'user_id' => $this->user, 'payload' => '', 'last_activity' => time()]);
            DB::table('auth_session_states')->insert(['session_id' => $oldSession, 'user_id' => $this->user, 'audience' => $role,
                'browser_binding_hash' => hash('sha256', $oldBinding), 'auth_version' => 0, 'primary_method' => $role === 'admin' ? 'password' : 'email_otp',
                'primary_verified_at' => now(), 'mfa_verified_at' => now(), 'mfa_method' => 'totp', 'absolute_expires_at' => now()->addHour()]);
            $token = $this->ready($case);
            $this->assertTrue(RecoveryGate::blocked($this->user));
            $this->assertSame(1, DB::table('users')->where('id', $this->user)->value('auth_version'));
            $factor = DB::table('user_totp_factors')->where('user_id', $this->user)->first();
            $this->assertNotNull(DB::table('auth_session_states')->where('session_id', $oldSession)->value('revoked_at'));
            $this->assertSame('invalidated', DB::table('auth_flows')->where('public_id', $oldFlow)->value('state'));
            $this->reject(fn () => app(MfaService::class)->finishLogin($oldFlow, $oldBinding, (new Google2FA)->oathTotp($oldSecret, intdiv(time(), 30))));
            $this->reject(fn () => app(MfaService::class)->finishLogin($oldFlow, $oldBinding, $oldSelector.'.'.$oldRecoveryCode, true));
            $this->assertNull($factor->secret_ciphertext);
            $this->assertNotNull($factor->disabled_at);
            $this->assertSame(0, DB::table('mfa_recovery_codes')->where('user_id', $this->user)->whereNull('revoked_at')->count());
            $this->reject(fn () => app(MfaService::class)->beginPasswordLogin($this->user.'@example.invalid', 'old-synthetic-password', str_repeat('b', 32)));
            $binding = bin2hex(random_bytes(32));
            $flow = $this->recovery->exchange($case->id, $token, $binding, '192.0.2.1');
            $this->reject(fn () => app(MfaService::class)->beginEnrollment($flow, $binding));
            $this->reject(fn () => app(MfaService::class)->beginRecoveryEnrollment($case->id, $binding));
            $challenge = $this->recovery->requestPrimary($case->id, $binding, '192.0.2.1');
            $mail = Mail::sent(EmailOtp::class)->last();
            preg_match('/<strong>([0-9]{8})<\/strong>/', $mail->render(), $match);
            $this->recovery->verifyPrimary($case->id, $binding, $challenge, $match[1], '192.0.2.1');
            $secret = app(MfaService::class)->beginRecoveryEnrollment($case->id, $binding)->reveal();
            $this->assertNotSame($oldSecret, $secret);
            $this->reject(fn () => app(MfaService::class)->beginRecoveryEnrollment($case->id, $binding));
            $code = (new Google2FA)->oathTotp($secret, intdiv(time(), 30));
            $codes = app(MfaService::class)->confirmRecoveryEnrollment($case->id, $binding, $code, '192.0.2.1', $password ? 'new-synthetic-password' : null)->reveal();
            $this->assertCount(3, $codes);
            $this->assertFalse(RecoveryGate::blocked($this->user));
            $this->assertSame('completed', DB::table('auth_recovery_cases')->where('id', $case->id)->value('state'));
            $this->assertSame(2, DB::table('users')->where('id', $this->user)->value('auth_version'));
            $this->assertSame(0, DB::table('auth_session_states')->where('user_id', $this->user)->whereNull('revoked_at')->count());
            if ($password) {
                $this->assertTrue(Hash::driver('argon2id')->check('new-synthetic-password', DB::table('users')->where('id', $this->user)->value('password')));
            }
            if ($lostPrimary) {
                $this->assertSame('new'.$this->user.'@example.invalid', DB::table('users')->where('id', $this->user)->value('email'));
            }
            if ($role === 'admin') {
                $newLogin = app(MfaService::class)->beginPasswordLogin(($lostPrimary ? 'new' : '').$this->user.'@example.invalid',
                    $password ? 'new-synthetic-password' : 'old-synthetic-password', str_repeat('n', 32));
                $proof = app(MfaService::class)->finishLogin($newLogin, str_repeat('n', 32), (new Google2FA)->oathTotp($secret, intdiv(time(), 30) + 1));
                $this->assertSame(2, $proof->auth_version);
            }
            $this->reject(fn () => $this->recovery->issue($case->id));
            $this->assertSame(1, DB::table('auth_recovery_grants')->where('case_id', $case->id)->count());
        } finally {
            if ($otherAdmins) {
                DB::table('users')->whereIn('id', $otherAdmins)->update(['role' => 'admin']);
            }
        }
    }

    public function test_one_approval_self_approval_forgery_receipt_and_hold_fail_closed(): void
    {
        $case = $this->fixture(elapsed: false);
        $a = $this->approval($case, $this->keys[0]);
        $forged = $a;
        $forged['signature'] = base64_encode(random_bytes(64));
        $this->reject(fn () => $this->recovery->approve($case->id, $forged));
        $this->boundary->acceptReceipt = false;
        $this->reject(fn () => $this->recovery->approve($case->id, $a));
        $this->boundary->acceptReceipt = true;
        $this->recovery->approve($case->id, $a);
        $this->reject(fn () => $this->recovery->issue($case->id));
        $this->reject(fn () => $this->recovery->approve($case->id, $a));
        $self = $this->signer('trustee');
        DB::table('auth_recovery_principals')->where('id', $self['principal_id'])->update(['revoked_at' => now()]);
        $this->reject(fn () => $this->recovery->approve($case->id, $this->approval($case, $self)));
        $this->recovery->approve($case->id, $this->approval($case, $this->keys[1]));
        $this->assertSame('hold', DB::table('auth_recovery_cases')->where('id', $case->id)->value('state'));
        $this->reject(fn () => $this->recovery->issue($case->id));
        $this->assertFalse(RecoveryGate::blocked($this->user));
    }

    public function test_revocation_and_cancel_never_restore_quarantined_factors(): void
    {
        $case = $this->fixture();
        $token = $this->ready($case);
        DB::table('auth_recovery_keys')->where('id', $this->keys[1]['key_id'])->update(['revoked_at' => now()]);
        // Exchange denied під live recheck; expired/revoked approvals не заважають close.
        try {
            $this->recovery->exchange($case->id, $token, str_repeat('a', 32), '192.0.2.2');
            $this->fail('Revoked key accepted');
        } catch (QueryException|RecoveryDenied) {
            $this->addToAssertionCount(1);
        }
        $this->recovery->close($case->id, 'cancelled');
        $this->assertTrue(RecoveryGate::blocked($this->user));
        $this->assertSame('cancelled', DB::table('auth_recovery_cases')->where('id', $case->id)->value('state'));
    }

    public function test_notice_failure_rolls_back_quarantine_and_audit(): void
    {
        $case = $this->fixture();
        // Підготувати live approvals без quarantine; ready() викликає issue, notice fault відкочує його.
        $this->boundary->failNotice = true;
        try {
            $this->ready($case);
            $this->fail('Notice failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic notice failure', $e->getMessage());
        }
        $this->assertFalse(RecoveryGate::blocked($this->user));
        $this->assertSame(0, DB::table('users')->where('id', $this->user)->value('auth_version'));
        $this->assertSame(0, DB::table('auth_recovery_grants')->where('case_id', $case->id)->count());
        $this->assertSame(0, DB::table('auth_audit_events')->where('case_reference', $case->id)->where('event_type', 'recovery.quarantined')->count());
    }

    public function test_target_initiator_operator_and_two_keys_of_one_person_cannot_approve(): void
    {
        $case = $this->fixture(elapsed: false);
        foreach ([$this->target, $this->initiator] as $excluded) {
            $key = $this->signer('operator', existing: $excluded);
            $this->reject(fn () => $this->recovery->approve($case->id, $this->approval($case, $key)));
        }
        $this->recovery->approve($case->id, $this->approval($case, $this->keys[0]));
        $alias = $this->signer('trustee', existing: $this->keys[0]['principal_id']);
        $this->reject(fn () => $this->recovery->approve($case->id, $this->approval($case, $alias)));
        $this->assertSame(1, DB::table('auth_recovery_approvals')->where('case_id', $case->id)->count());
    }

    public function test_binding_replay_and_stale_registry_do_not_exchange_grant(): void
    {
        $case = $this->fixture();
        $token = $this->ready($case);
        $binding = str_repeat('r', 32);
        $this->recovery->exchange($case->id, $token, $binding, '192.0.2.13');
        try {
            $this->recovery->exchange($case->id, $token, $binding, '192.0.2.13');
            $this->fail('Replay accepted');
        } catch (QueryException|RecoveryDenied) {
            $this->addToAssertionCount(1);
        }
        $this->reject(fn () => app(MfaService::class)->beginRecoveryEnrollment($case->id, str_repeat('x', 32)));
        $this->boundary->current['registry_version'] = 2;
        $this->reject(fn () => $this->recovery->issue($case->id));
        $this->assertTrue(RecoveryGate::blocked($this->user));
    }

    public function test_old_client_login_and_sessions_remain_blocked_when_recovery_disabled(): void
    {
        $case = $this->fixture('client');
        $binding = str_repeat('s', 32);
        $client = app(ClientAuth::class);
        $response = $client->request('login', $this->user.'@example.invalid', null, $binding, '192.0.2.14');
        preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);
        $this->ready($case);
        config(['auth5.enabled' => false]);
        try {
            $client->verify($response['challenge_id'], $match[1], $binding, '192.0.2.14');
            $this->fail('Old OTP admitted');
        } catch (AuthDenied) {
            $this->addToAssertionCount(1);
        }
        $this->reject(fn () => DB::table('auth_flows')->insert(['public_id' => (string) Str::uuid(), 'browser_binding_hash' => hash('sha256', $binding),
            'audience' => 'client', 'purpose' => 'login', 'user_id' => $this->user, 'auth_version_at_start' => 1, 'expires_at' => now()->addMinutes(5)]));
        $this->assertTrue(RecoveryGate::blocked($this->user));
    }

    public function test_audit_failure_rolls_back_security_mutation(): void
    {
        $case = $this->fixture();
        DB::beginTransaction();
        DB::unprepared("CREATE FUNCTION b3_test_audit_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
            IF NEW.event_type='recovery.quarantined' THEN RAISE EXCEPTION 'Synthetic audit failure'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER b3_test_audit_fault BEFORE INSERT ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION b3_test_audit_fault();");
        try {
            $this->ready($case);
            $this->fail('Audit fault ignored');
        } catch (QueryException) {
            // issue() rollback до savepoint зберігає fixture approvals, але не security mutation.
            $this->assertFalse(RecoveryGate::blocked($this->user));
            $this->assertSame(0, DB::table('users')->where('id', $this->user)->value('auth_version'));
            $this->assertSame(0, DB::table('auth_recovery_grants')->where('case_id', $case->id)->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_b4_outbox_insert_fault_rolls_back_quarantine_and_finalize(): void
    {
        $case = $this->fixture();
        $this->ready($case, false);
        DB::unprepared("CREATE FUNCTION b4_recovery_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.notice_type='credentials_revoked' THEN RAISE EXCEPTION 'CANARY'; END IF; RETURN NEW; END $$;
            CREATE TRIGGER b4_recovery_fault BEFORE INSERT ON auth_security_notice_outbox FOR EACH ROW EXECUTE FUNCTION b4_recovery_fault();");
        try {
            try {
                $this->recovery->issue($case->id);
                $this->fail('Outbox fault ignored');
            } catch (NoticeUnavailable $e) {
                $this->assertNull($e->getPrevious());
                $this->assertFalse(RecoveryGate::blocked($this->user));
                $this->assertSame(0, DB::table('users')->where('id', $this->user)->value('auth_version'));
                $this->assertSame(0, DB::table('auth_recovery_grants')->where('case_id', $case->id)->count());
            }
        } finally {
            DB::unprepared('DROP TRIGGER b4_recovery_fault ON auth_security_notice_outbox; DROP FUNCTION b4_recovery_fault();');
        }
        [$binding, $ip, $secret] = $this->pendingEnrollment($case, true);
        DB::unprepared("CREATE FUNCTION b4_recovery_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.notice_type='recovery_completed' THEN RAISE EXCEPTION 'CANARY'; END IF; RETURN NEW; END $$;
            CREATE TRIGGER b4_recovery_fault BEFORE INSERT ON auth_security_notice_outbox FOR EACH ROW EXECUTE FUNCTION b4_recovery_fault();");
        try {
            try {
                app(MfaService::class)->confirmRecoveryEnrollment($case->id, $binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30)), $ip);
                $this->fail('Finalize outbox fault ignored');
            } catch (NoticeUnavailable) {
                $this->assertTrue(RecoveryGate::blocked($this->user));
                $this->assertSame(1, DB::table('users')->where('id', $this->user)->value('auth_version'));
                $this->assertSame('enrolling', DB::table('auth_recovery_cases')->where('id', $case->id)->value('state'));
                $this->assertNull(DB::table('user_totp_factors')->where('user_id', $this->user)->value('confirmed_at'));
                $this->assertSame(0, DB::table('mfa_recovery_codes')->where('user_id', $this->user)->count());
            }
        } finally {
            DB::unprepared('DROP TRIGGER b4_recovery_fault ON auth_security_notice_outbox; DROP FUNCTION b4_recovery_fault();');
        }
    }

    public function test_history_and_registry_updates_and_populated_down_are_rejected(): void
    {
        $case = $this->fixture();
        $this->ready($case);
        foreach (['auth_recovery_approvals', 'auth_recovery_events'] as $table) {
            $this->reject(fn () => DB::table($table)->where('case_id', $case->id)->delete());
            $this->reject(fn () => DB::statement('TRUNCATE '.$table));
        }
        $this->reject(fn () => DB::table('auth_recovery_cases')->where('id', $case->id)->update(['intent_digest' => str_repeat('a', 64)]));
        $this->reject(fn () => DB::table('auth_recovery_keys')->where('id', $this->keys[0]['key_id'])->update(['registry_version' => 2]));
        $migration = require database_path('migrations/2026_10_22_002000_create_auth_recovery.php');
        try {
            $migration->down();
            $this->fail('Populated down accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Відкат скасовано', $e->getMessage());
        }
    }

    public function test_expired_case_approval_and_grant_are_not_authority(): void
    {
        $case = $this->fixture();
        // TTL48h approval: прострочений exact signed envelope відхиляється verifier/DB.
        $expired = $this->approval($case, $this->keys[0]);
        $expired['expires_at'] = now()->subSecond()->format('Y-m-d H:i:s.uP');
        $expired['signature'] = base64_encode(sodium_crypto_sign_detached(app(RecoverySignatures::class)->envelope($case, $expired), $this->keys[0]['secret']));
        $this->reject(fn () => $this->recovery->approve($case->id, $expired));
        // Owner-only fixture створює historical ready/gate із уже простроченим grant.
        foreach ($this->keys as $key) {
            $a = $this->approval($case, $key);
            DB::table('auth_recovery_approvals')->insert(['case_id' => $case->id, 'principal_id' => $a['principal_id'], 'key_id' => $a['key_id'],
                'intent_digest' => $case->intent_digest, 'signature' => DB::raw("decode('".bin2hex(base64_decode($a['signature']))."','hex')"),
                'evidence_digest' => $a['evidence_digest'], 'verification_receipt_id' => $a['verification_receipt_id'],
                'verified_at' => now()->subHours(24)->subMinutes(59), 'expires_at' => $a['expires_at']]);
        }
        DB::table('auth_recovery_cases')->where('id', $case->id)->update(['state' => 'hold', 'hold_until' => now()->subMinutes(50)]);
        DB::table('auth_recovery_cases')->where('id', $case->id)->update(['state' => 'ready']);
        DB::table('users')->where('id', $this->user)->increment('auth_version');
        DB::table('auth_recovery_gates')->insert(['user_id' => $this->user, 'case_id' => $case->id, 'blocked_at' => now()->subMinutes(20)]);
        $token = bin2hex(random_bytes(32));
        DB::table('auth_recovery_grants')->insert(['id' => (string) Str::uuid(), 'case_id' => $case->id, 'generation' => 1,
            'token_hash' => hash('sha256', $token), 'auth_version' => 1, 'issued_at' => now()->subMinutes(20), 'expires_at' => now()->subMinutes(6)]);
        try {
            $this->recovery->exchange($case->id, $token, str_repeat('e', 32), '192.0.2.24');
            $this->fail('Expired grant exchanged');
        } catch (RecoveryDenied|QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->recovery->close($case->id, 'expired');
        $this->assertTrue(RecoveryGate::blocked($this->user));
        $this->assertNotNull(DB::table('auth_recovery_grants')->where('case_id', $case->id)->value('revoked_at'));
        // Case TTL перевіряється після locks, historical row не надає authority.
        $row = (array) $case;
        $clock = now();
        $row['id'] = (string) Str::uuid();
        $row['intent_nonce'] = (string) Str::uuid();
        $row['expected_auth_version'] = 1;
        $row['created_at'] = $clock->copy()->subHours(73)->format('Y-m-d H:i:s.uP');
        $row['expires_at'] = $clock->copy()->subHour()->format('Y-m-d H:i:s.uP');
        $row['intent_digest'] = hash('sha256', app(RecoverySignatures::class)->intent((object) $row));
        DB::table('auth_recovery_cases')->insert($row);
        $this->reject(fn () => $this->recovery->issue($row['id']));
    }

    public function test_legacy_password_only_context_is_denied_even_with_mfa_flag_off(): void
    {
        $case = $this->fixture();
        $this->ready($case);
        config(['mfa.enabled' => false]);
        $user = User::findOrFail($this->user);
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
    }

    public function test_pending_and_primary_ttls_are_rechecked_after_locks(): void
    {
        foreach ([true, false] as $expiredFlow) {
            $case = $this->fixture();
            $this->ready($case, false);
            DB::table('auth_recovery_cases')->where('id', $case->id)->update(['state' => 'ready']);
            DB::table('users')->where('id', $this->user)->increment('auth_version');
            DB::table('auth_recovery_gates')->insert(['user_id' => $this->user, 'case_id' => $case->id, 'blocked_at' => now()->subMinutes(10)]);
            $gid = (string) Str::uuid();
            DB::table('auth_recovery_grants')->insert(['id' => $gid, 'case_id' => $case->id, 'generation' => 1,
                'token_hash' => hash('sha256', Str::uuid()), 'auth_version' => 1, 'issued_at' => now()->subMinutes(10), 'expires_at' => now()->addMinutes(4)]);
            $binding = bin2hex(random_bytes(32));
            $fid = DB::table('auth_flows')->insertGetId(['public_id' => (string) Str::uuid(), 'browser_binding_hash' => hash('sha256', $binding),
                'audience' => 'admin', 'purpose' => 'mfa_enroll', 'action' => 'assisted_recovery', 'user_id' => $this->user,
                'auth_version_at_start' => 1, 'created_at' => now()->subMinutes(9), 'expires_at' => $expiredFlow ? now()->subMinute() : now()->addSeconds(30)]);
            DB::table('auth_recovery_grants')->where('id', $gid)->update(['exchanged_at' => now()->subMinutes(9), 'binding_hash' => hash('sha256', $binding), 'enrollment_flow_id' => $fid]);
            DB::table('auth_recovery_cases')->where('id', $case->id)->update(['state' => 'enrolling']);
            if (! $expiredFlow) {
                DB::table('auth_flows')->where('id', $fid)->update(['state' => 'primary_verified', 'primary_verified_at' => now()->subMinutes(6)]);
            }
            $this->reject(fn () => app(MfaService::class)->beginRecoveryEnrollment($case->id, $binding));
            $this->assertTrue(RecoveryGate::blocked($this->user));
            $this->user = random_int(9000000000, 9999999999);
        }
    }

    private function pendingEnrollment(object $case, bool $ready = false): array
    {
        $token = $ready ? $this->recovery->issue($case->id)->reveal() : $this->ready($case);
        $binding = bin2hex(random_bytes(32));
        $ip = '192.0.2.'.random_int(30, 200);
        $this->recovery->exchange($case->id, $token, $binding, $ip);
        $challenge = $this->recovery->requestPrimary($case->id, $binding, $ip);
        preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);
        $this->recovery->verifyPrimary($case->id, $binding, $challenge, $match[1], $ip);
        $secret = app(MfaService::class)->beginRecoveryEnrollment($case->id, $binding)->reveal();

        return [$binding, $ip, $secret];
    }

    public function test_five_totp_failures_commit_and_deny_further_finalize(): void
    {
        $case = $this->fixture();
        [$binding, $ip, $secret] = $this->pendingEnrollment($case);
        for ($i = 0; $i < 5; $i++) {
            try {
                app(MfaService::class)->confirmRecoveryEnrollment($case->id, $binding, 'invalid', $ip);
                $this->fail('Invalid TOTP admitted');
            } catch (RecoveryDenied) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            app(MfaService::class)->confirmRecoveryEnrollment($case->id, $binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30)), $ip);
            $this->fail('Budget bypass');
        } catch (RecoveryDenied) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, DB::table('users')->where('id', $this->user)->value('auth_version'));
        $this->assertTrue(RecoveryGate::blocked($this->user));
        $this->assertSame(0, DB::table('mfa_recovery_codes')->where('user_id', $this->user)->count());
        $this->assertSame(6, DB::table('auth_recovery_events')->where('case_id', $case->id)->where('event_type', 'denied')->count());
    }

    public function test_finalize_dependency_failure_does_not_install_factor_or_release_gate(): void
    {
        $case = $this->fixture();
        [$binding, $ip, $secret] = $this->pendingEnrollment($case);
        $this->boundary->failNotice = true;
        try {
            app(MfaService::class)->confirmRecoveryEnrollment($case->id, $binding, (new Google2FA)->oathTotp($secret, intdiv(time(), 30)), $ip);
            $this->fail('Final notice failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic notice failure', $e->getMessage());
        }
        $this->assertSame(1, DB::table('users')->where('id', $this->user)->value('auth_version'));
        $this->assertTrue(RecoveryGate::blocked($this->user));
        $this->assertSame('enrolling', DB::table('auth_recovery_cases')->where('id', $case->id)->value('state'));
        $factor = DB::table('user_totp_factors')->where('user_id', $this->user)->first();
        $this->assertNull($factor->confirmed_at);
        $this->assertNotNull($factor->pending_secret_ciphertext);
        $this->assertSame(0, DB::table('mfa_recovery_codes')->where('user_id', $this->user)->count());
    }

    public function test_missing_dependency_and_production_environment_cannot_activate_recovery(): void
    {
        $case = $this->fixture();
        $service = new RecoveryService(app(ControlledAuth::class), app(AuthBudget::class), app(RecoverySignatures::class), app(EmailNormalizer::class));
        $this->reject(fn () => $service->issue($case->id));
        app()->instance('env', 'production');
        $this->reject(fn () => $this->recovery->issue($case->id));
        app()->instance('env', 'testing');
    }
}
