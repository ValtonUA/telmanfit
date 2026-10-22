<?php

namespace App\Services\Auth\Recovery;

use App\Jobs\DeliverEmailOtp;
use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\PostgresBudgets;
use App\Services\Auth\Budgets\SyntheticBudgets;
use App\Services\Auth\Client\AuthBudget;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\Client\EmailNormalizer;
use App\Services\Auth\SecretValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** B3 domain adapter. D3 transport та B4 outbox навмисно не мають default binding. */
final class RecoveryService
{
    public function __construct(
        private ControlledAuth $auth,
        private AuthBudget $budget,
        private RecoverySignatures $signatures,
        private EmailNormalizer $emails,
        private ?RecoveryBoundary $boundary = null,
    ) {}

    private function boundary(): RecoveryBoundary
    {
        // Навіть помилковий DI binding не активує recovery у production.
        if (! app()->environment(['local', 'testing']) || ! $this->boundary || ! extension_loaded('sodium')) {
            throw new RecoveryDenied;
        }
        $this->auth->assert();

        return $this->boundary;
    }

    private function context(?object $case = null): array
    {
        $context = $this->boundary()->context();
        if (! isset($context['deployment_id'], $context['restore_epoch'], $context['registry_version'])
            || ($context['restore_epoch'] ?? null) !== DB::table('auth_security_runtime')->where('id', 1)->value('restore_epoch')
            || ! Str::isUuid($context['restore_epoch']) || (int) $context['registry_version'] < 1
            || ($case && ($case->deployment_id !== $context['deployment_id'] || $case->restore_epoch !== $context['restore_epoch']
                || $case->registry_version !== (int) $context['registry_version']))) {
            throw new RecoveryDenied;
        }

        return $context;
    }

    public function create(int $target, string $targetPrincipal, string $initiator, ?string $operator, string $action, bool $replacePassword = false, ?string $replacementEmail = null): object
    {
        $context = $this->context();

        return DB::transaction(function () use ($target, $targetPrincipal, $initiator, $operator, $action, $replacePassword, $replacementEmail, $context) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::select('SELECT pg_advisory_xact_lock_shared(6060303)');
            DB::select('SELECT pg_advisory_xact_lock(4040404)');
            $user = DB::table('users')->where('id', $target)->lockForUpdate()->first() ?? throw new RecoveryDenied;
            $now = $this->auth->now();
            $email = $replacementEmail === null ? null : $this->emails->normalize($replacementEmail);
            if (($action === 'replace_primary_mfa') !== ($email !== null) || ($replacePassword && $user->role !== 'admin')) {
                throw new RecoveryDenied;
            }
            $row = (object) ['id' => (string) Str::uuid(), 'target_user_id' => $target, 'target_principal_id' => $targetPrincipal,
                'initiator_principal_id' => $initiator, 'operator_principal_id' => $operator, 'target_role' => $user->role,
                'action' => $action, 'replace_admin_password' => $replacePassword, 'expected_auth_version' => $user->auth_version,
                'registry_version' => (int) $context['registry_version'], 'policy_version' => 1,
                'deployment_id' => $context['deployment_id'], 'restore_epoch' => $context['restore_epoch'],
                'intent_nonce' => (string) Str::uuid(), 'replacement_email_ciphertext' => $email === null ? null : Crypt::encryptString(trim($replacementEmail)),
                'replacement_email_mac' => $email === null ? null : $this->auth->fingerprint($email),
                'email_key_version' => $email === null ? null : config('auth4.key_version'),
                'created_at' => $now->format('Y-m-d H:i:s.uP'), 'expires_at' => $now->addHours(72)->format('Y-m-d H:i:s.uP'),
                'reason_code' => $email === null ? 'lost_mfa' : 'lost_primary'];
            if (! $this->boundary()->authorize('create', $row)) {
                throw new RecoveryDenied;
            }
            $row->intent_digest = hash('sha256', $this->signatures->intent($row));
            DB::table('auth_recovery_cases')->insert((array) $row);
            $this->event($row, 'created');

            return $row;
        });
    }

    /** Лише verifier DB identity має INSERT approval; runtime не може записати verified row. */
    public function approve(string $id, array $approval): void
    {
        $this->withCase($id, function ($case) use ($approval) {
            $key = DB::table('auth_recovery_keys')->where('id', $approval['key_id'])->where('principal_id', $approval['principal_id'])->first() ?? throw new RecoveryDenied;
            $public = $this->bytes($key->public_key);
            if (! hash_equals($key->fingerprint, hash('sha256', $public)) || ! $this->boundary()->receipt($case, $approval)) {
                throw new RecoveryDenied;
            }
            $this->signatures->verify($case, $approval, $public);
            DB::table('auth_recovery_approvals')->insert([
                'case_id' => $case->id, 'principal_id' => $approval['principal_id'], 'key_id' => $approval['key_id'],
                'intent_digest' => $case->intent_digest, 'signature' => DB::raw("decode('".bin2hex(base64_decode($approval['signature'], true))."','hex')"),
                'evidence_digest' => $approval['evidence_digest'], 'verification_receipt_id' => $approval['verification_receipt_id'],
                'verified_at' => $this->auth->stamp(), 'expires_at' => $approval['expires_at'],
            ]);
            DB::select('SELECT auth6b_accept_approval(?)', [$case->id]);
            $this->event($case, 'approved', $approval['principal_id']);
        }, false);
    }

    public function issue(string $id): SecretValue
    {
        $token = bin2hex(random_bytes(32));

        return $this->withCase($id, function ($case) use ($token) {
            DB::select('SELECT auth6b_quarantine(?, ?, ?)', [$case->id, (string) Str::uuid(), hash('sha256', $token)]);
            $this->event($case, 'quarantined');

            return new SecretValue($token);
        });
    }

    public function exchange(string $id, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $binding, string $ip): string
    {
        $this->admit([['recovery.exchange:ip:'.$ip, 10, 60], ['recovery.exchange:case:'.$id, 5, 900]]);
        if (! preg_match('/^[a-f0-9]{64}$/D', $token) || strlen($binding) < 32) {
            throw new RecoveryDenied;
        }

        return $this->withCase($id, function ($case) use ($token, $binding) {
            $flow = (string) Str::uuid();
            DB::select('SELECT auth6b_exchange(?, ?, ?, ?)', [$case->id, hash('sha256', $token), hash('sha256', $binding), $flow]);
            $this->event($case, 'exchanged');

            return $flow;
        });
    }

    /** Використовує ті самі P3 budgets, auth_challenges та delivery job. */
    public function requestPrimary(string $id, string $binding, string $ip): string
    {
        $this->admit([['issue:ip:15:'.$ip, 20, 900], ['issue:ip:day:'.$ip, 100, 86400], ['issue:global', 1000, 3600]]);
        $delivery = null;
        $result = $this->withEnrollment($id, $binding, function ($user, $flow, $factor, $case) use (&$delivery) {
            if ($flow->state !== 'pending') {
                throw new RecoveryDenied;
            }
            $email = $this->primaryEmail($case, $user);
            $normalized = $this->emails->normalize($email);
            $limits = [['cooldown:recovery:'.$normalized, 1, 60], ['issue:15:recovery:'.$normalized, 3, 900],
                ['issue:day:recovery:'.$normalized, 10, 86400], ['issue:all:'.$normalized, 15, 86400]];
            if (SyntheticBudgets::enabled()) {
                if (! $this->budget->emailGroup($limits, $normalized)) {
                    return null;
                }
            } else {
                foreach ($limits as [$key, $limit, $seconds]) {
                    if (! $this->budget->take($key, $limit, $seconds) || $this->budget->blocked($normalized)) {
                        return null;
                    }
                }
            }
            $old = DB::table('auth_challenges')->where('flow_id', $flow->id)->orderBy('id')->lockForUpdate()->get();
            if ($old->sum('attempts') >= 5) {
                return null;
            }
            DB::table('auth_challenges')->where('flow_id', $flow->id)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => $this->auth->stamp()]);
            $generation = $flow->generation + ($old->isEmpty() ? 0 : 1);
            if ($generation !== $flow->generation) {
                DB::table('auth_flows')->where('id', $flow->id)->update(['generation' => $generation]);
                $flow->generation = $generation;
            }
            $challenge = (string) Str::uuid();
            $code = sprintf('%08d', random_int(0, 99999999));
            $version = (string) config('auth4.key_version');
            DB::table('auth_challenges')->insert(['public_id' => $challenge, 'flow_id' => $flow->id, 'generation' => $generation,
                'email_normalized' => $normalized, 'code_mac' => $this->otpMac($flow, $challenge, $normalized, $code, $version),
                'key_version' => $version, 'max_attempts' => 5, 'created_at' => $this->auth->stamp(),
                'expires_at' => $this->auth->now()->addMinutes(5)->min(CarbonImmutable::parse($flow->expires_at))->format('Y-m-d H:i:s.uP')]);
            $delivery = new DeliverEmailOtp($challenge, $email, $code);
            $this->event($case, 'primary_sent');

            return $challenge;
        }, false);
        try {
            dispatch($delivery);
        } catch (\Throwable) {
            // Delivery failure не надає proof; challenge стає unusable, gate залишається.
            DB::table('auth_challenges')->where('public_id', $result)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => $this->auth->stamp()]);
            throw new RecoveryDenied;
        }

        return $result;
    }

    public function verifyPrimary(string $id, string $binding, string $challenge, #[\SensitiveParameter] string $code, string $ip): void
    {
        $this->admit([['verify:ip:'.$ip, 10, 60]]);
        $this->withEnrollment($id, $binding, function ($user, $flow, $factor, $case) use ($challenge, $code) {
            $row = DB::table('auth_challenges')->where('public_id', $challenge)->where('flow_id', $flow->id)->lockForUpdate()->first();
            if ($flow->state !== 'pending' || ! $row || $row->consumed_at || $row->invalidated_at || $row->generation !== $flow->generation
                || $row->attempts >= 5 || DB::table('auth_challenges')->where('flow_id', $flow->id)->sum('attempts') >= 5
                || CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo($this->auth->now()) || $this->budget->blocked($row->email_normalized)) {
                return null;
            }
            if (! preg_match('/^[0-9]{8}$/D', $code) || ! hash_equals($row->code_mac, $this->otpMac($flow, $challenge, $row->email_normalized, $code, $row->key_version))) {
                DB::table('auth_challenges')->where('id', $row->id)->increment('attempts');
                $this->budget->failure($row->email_normalized);

                return null;
            }
            DB::table('auth_challenges')->where('id', $row->id)->update(['consumed_at' => $this->auth->stamp()]);
            DB::table('auth_flows')->where('id', $flow->id)->update(['state' => 'primary_verified', 'primary_verified_at' => $this->auth->stamp()]);
            $this->event($case, 'primary_verified');

            return true;
        }, false);
    }

    /** Єдина authority для restricted MfaService; callback виконується під тими самими locks. */
    public function withEnrollment(string $id, string $binding, callable $operation, bool $primary = true): mixed
    {
        return $this->withCase($id, function ($case) use ($binding, $operation, $primary) {
            $grant = DB::table('auth_recovery_grants')->where('case_id', $case->id)->first();
            $user = DB::table('users')->where('id', $case->target_user_id)->first();
            if ($case->state !== 'enrolling' || ! $grant?->exchanged_at || $grant->revoked_at || $user->auth_version !== $grant->auth_version
                || ! hash_equals($grant->binding_hash, hash('sha256', $binding)) || CarbonImmutable::parse($grant->expires_at)->lessThanOrEqualTo($this->auth->now())
                || ! DB::table('auth_recovery_gates')->where('case_id', $case->id)->whereNull('released_at')->exists()) {
                throw new RecoveryDenied;
            }
            $flow = DB::table('auth_flows')->where('id', $grant->enrollment_flow_id)->lockForUpdate()->first();
            if (! $flow || $flow->action !== 'assisted_recovery' || $flow->purpose !== 'mfa_enroll'
                || $flow->user_id !== $user->id || $flow->auth_version_at_start !== $user->auth_version
                || ! hash_equals($flow->browser_binding_hash, $grant->binding_hash) || ! in_array($flow->state, ['pending', 'primary_verified'], true)
                || CarbonImmutable::parse($flow->expires_at)->lessThanOrEqualTo($this->auth->now())
                || ($primary && ($flow->state !== 'primary_verified' || CarbonImmutable::parse($flow->primary_verified_at)->addMinutes(5)->lessThanOrEqualTo($this->auth->now())))) {
                throw new RecoveryDenied;
            }
            // Challenge locks до factor; P3 failure totals зберігаються на кожному retry.
            DB::table('auth_challenges')->where('flow_id', $flow->id)->orderBy('id')->lockForUpdate()->get();
            $factor = DB::table('user_totp_factors')->where('user_id', $user->id)->lockForUpdate()->first();
            $result = $operation($user, $flow, $factor, $case);
            if ($result === null) {
                $this->event($case, 'denied');

                return null;
            }

            return $result;
        });
    }

    public function admitTotp(string $id, string $ip): void
    {
        $this->context();
        if (DB::transactionLevel() !== 0 || ! Str::isUuid($id)) {
            throw new RecoveryDenied;
        }
    }

    /** Виклик лише під withEnrollment locks; рахуються failures, не успішні finalize. */
    public function totpAllowed(object $user, object $flow, string $ip, bool $failure = false): bool
    {
        if (SyntheticBudgets::enabled()) {
            return app(PostgresBudgets::class)->failures([
                new BudgetKey(BudgetScope::RecoveryTotpFlow, $flow->public_id),
                new BudgetKey(BudgetScope::RecoveryTotpUser, (string) $user->id),
                new BudgetKey(BudgetScope::RecoveryTotpIp, $ip),
            ], $failure)->allowed;
        }
        $allowed = true;
        foreach ([['recovery.totp:flow:'.$flow->public_id, 5, 900], ['recovery.totp:user:'.$user->id, 10, 900], ['recovery.totp:ip:'.$ip, 20, 900]] as [$key, $limit, $seconds]) {
            $allowed = ($failure ? $this->budget->take($key, $limit, $seconds) : $this->budget->allows($key, $limit, $seconds)) && $allowed;
        }

        return $allowed;
    }

    /** Виклик усередині withEnrollment після shared MFA install; plaintext password не зберігається. */
    public function complete(object $case, object $user, ?string $passwordHash): void
    {
        if ($case->replace_admin_password !== ($passwordHash !== null) || ($passwordHash !== null && $user->role !== 'admin')) {
            throw new RecoveryDenied;
        }
        $case->noticeOldEmail = $user->email;
        $changes = [];
        if ($passwordHash !== null) {
            $changes['password'] = $passwordHash;
        }
        if ($case->action === 'replace_primary_mfa') {
            $email = $this->primaryEmail($case, $user);
            $normalized = $this->emails->normalize($email);
            foreach (DB::table('users')->where('id', '<>', $user->id)->whereNotNull('email')->get() as $other) {
                if ($this->emails->normalize($other->email) === $normalized
                    || ($other->email_normalized !== null && $this->emails->normalize($other->email) !== $other->email_normalized)) {
                    throw new RecoveryDenied;
                }
            }
            $changes += ['email' => $email, 'email_normalized' => $normalized, 'email_verified_at' => $this->auth->stamp()];
        }
        if ($changes) {
            DB::table('users')->where('id', $user->id)->update($changes + ['remember_token' => null]);
        }
        DB::select('SELECT auth6b_complete(?)', [$case->id]);
        $this->event($case, 'completed');
    }

    public function close(string $id, string $state): void
    {
        $this->withCase($id, function ($case) use ($state) {
            if (! $this->boundary()->authorize('close', $case)) {
                throw new RecoveryDenied;
            }
            DB::select('SELECT auth6b_close(?, ?)', [$case->id, $state]);
            $this->event($case, $state);
        }, false);
    }

    public function hashPassword(#[\SensitiveParameter] ?string $password): ?string
    {
        if ($password === null) {
            return null;
        }
        if (Validator::make(['password' => $password], ['password' => ['required', 'string', 'max:1024', Password::defaults()]])->fails()) {
            throw new RecoveryDenied;
        }

        return Hash::driver('argon2id')->make($password);
    }

    public function event(object $case, string $event, ?string $actor = null): void
    {
        $request = (string) Str::uuid();
        DB::table('auth_recovery_events')->insert(['case_id' => $case->id, 'event_type' => $event,
            'actor_principal_id' => $actor, 'request_id' => $request]);
        DB::table('auth_audit_events')->insert(['subject_user_id' => $case->target_user_id, 'event_type' => 'recovery.'.$event,
            'outcome' => $event === 'denied' ? 'denied' : 'success', 'case_reference' => $case->id, 'request_id' => $request]);
        $this->boundary()->notice($case, $event, $request);
        app(RecoveryNoticeWriter::class)->notice($case, $event, $request);
    }

    private function withCase(string $id, callable $operation, bool $approvals = true): mixed
    {
        $this->context();
        if (! Str::isUuid($id)) {
            throw new RecoveryDenied;
        }
        $result = DB::transaction(function () use ($id, $operation, $approvals) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $case = DB::selectOne('SELECT * FROM auth6b_lock_case(?)', [$id]);
            $this->context($case);
            if ($approvals) {
                DB::select('SELECT auth6b_check_approvals(?)', [$id]);
                // Recheck exact bytes та receipt незалежно від попереднього verifier write.
                foreach (DB::table('auth_recovery_approvals')->where('case_id', $id)->get() as $approval) {
                    $key = DB::table('auth_recovery_keys')->where('id', $approval->key_id)->first();
                    $data = (array) $approval;
                    $data['signature'] = base64_encode($this->bytes($approval->signature));
                    $public = $this->bytes($key->public_key);
                    if (! hash_equals($key->fingerprint, hash('sha256', $public)) || ! $this->boundary()->receipt($case, $data)) {
                        throw new RecoveryDenied;
                    }
                    $this->signatures->verify($case, $data, $public);
                }
            }

            return $operation($case);
        });
        if ($result === null && $approvals) {
            throw new RecoveryDenied;
        }

        return $result;
    }

    private function admit(array $limits): void
    {
        $this->context();
        // Admission комітиться окремо до domain locks; rollback denial не повертає бюджет.
        if (DB::transactionLevel() !== 0) {
            throw new RecoveryDenied;
        }
        $allowed = $this->auth->transaction(function () use ($limits) {
            $ok = true;
            foreach ($limits as [$key, $limit, $seconds]) {
                $ok = $this->budget->take($key, $limit, $seconds) && $ok;
            }

            return $ok;
        });
        if (! $allowed) {
            throw new RecoveryDenied;
        }
    }

    private function primaryEmail(object $case, object $user): string
    {
        if ($case->action === 'replace_primary_mfa') {
            if ($case->email_key_version !== config('auth4.key_version')) {
                throw new RecoveryDenied;
            }
            $email = Crypt::decryptString($case->replacement_email_ciphertext);
            if (! hash_equals($case->replacement_email_mac, $this->auth->fingerprint($this->emails->normalize($email)))) {
                throw new RecoveryDenied;
            }

            return $email;
        }
        if (! $user->email_verified_at || ! $user->email || $this->emails->normalize($user->email) !== $user->email_normalized) {
            throw new RecoveryDenied;
        }

        return $user->email;
    }

    private function otpMac(object $flow, string $challenge, string $email, string $code, string $version): string
    {
        return hash_hmac('sha256', json_encode(['recovery-email-otp-v1', $version, $flow->public_id, $challenge, $flow->generation,
            $flow->purpose, $flow->audience, $flow->browser_binding_hash, $email, $code], JSON_THROW_ON_ERROR), $this->auth->key($version));
    }

    private function bytes(mixed $value): string
    {
        return is_resource($value) ? stream_get_contents($value) : $value;
    }
}
