<?php

namespace App\Services\Auth;

use App\Support\Auth\AuthSecretCodec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Спільний domain contract: жодних Filament, HTTP, guard або email OTP залежностей. */
final class MfaService
{
    public function __construct(private AuthSecretCodec $codec, private MfaPolicy $policy) {}

    public function beginPasswordLogin(string $email, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $binding): string
    {
        $this->policy->assertControlled();
        // Exact delivery email чинного Filament; normalized-key/backfill не запроваджується.
        $candidate = DB::table('users')->where('email', $email)->first();
        $valid = Hash::check($password, $candidate?->password ?? '$2y$04$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
        if (! $candidate || ! $valid || $candidate->role !== 'admin') {
            throw new MfaDenied;
        }

        return DB::transaction(function () use ($candidate, $binding) {
            $user = $this->user($candidate->id);
            if ($user->role !== 'admin' || $user->password !== $candidate->password) {
                throw new MfaDenied;
            }
            $this->budget($user->id);
            $this->invalidateFlows($user->id, $binding);
            $id = (string) Str::uuid();
            $now = $this->now();
            DB::table('auth_flows')->insert([
                'public_id' => $id, 'browser_binding_hash' => hash('sha256', $binding),
                'audience' => 'admin', 'purpose' => 'login', 'user_id' => $user->id,
                'auth_version_at_start' => $user->auth_version, 'state' => 'primary_verified',
                'primary_verified_at' => $now->format('Y-m-d H:i:s.uP'), 'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'expires_at' => $now->addSeconds((int) config('mfa.flow_seconds'))->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit($user->id, 'mfa.primary_verified');

            return $id;
        });
    }

    /** AUTH-4 має передавати лише власний серверний primary_verified email_otp flow. */
    public function beginEnrollment(string $flowId, #[\SensitiveParameter] string $binding): SecretValue
    {
        return $this->withFlow($flowId, $binding, function ($user, $flow, $factor) {
            if ($factor?->confirmed_at && $flow->action !== 'replace_factor') {
                return null;
            }
            if (! in_array($flow->action, [null, 'replace_factor'], true)) {
                return null;
            }
            $secret = (new Google2FA)->generateSecretKey(32);
            $data = ['pending_secret_ciphertext' => $this->codec->encryptTotp($secret),
                'pending_key_version' => config('mfa.key_version'), 'pending_expires_at' => $flow->expires_at,
                'updated_at' => $this->stamp()];
            if ($factor) {
                DB::table('user_totp_factors')->where('user_id', $user->id)->update($data);
            } else {
                DB::table('user_totp_factors')->insert($data + ['user_id' => $user->id, 'created_at' => $data['updated_at']]);
            }
            DB::table('auth_flows')->where('id', $flow->id)->update(['capability_hash' => hash('sha256', $data['pending_secret_ciphertext'])]);
            $this->audit($user->id, 'mfa.enrollment_started');

            return new SecretValue($secret);
        });
    }

    /** Підтвердження не авторизує сесію: адміністратор виконує новий повний вхід. */
    public function confirmEnrollment(string $flowId, #[\SensitiveParameter] string $binding, #[\SensitiveParameter] string $code): SecretValue
    {
        // Argon hashing поза locks; plaintext лише у поверненому об'єкті.
        [$batch, $codes, $rows] = $this->newRecoveryBatch();

        return $this->withFlow($flowId, $binding, function ($user, $flow, $factor) use ($code, $batch, $codes, $rows) {
            if (! $factor?->pending_secret_ciphertext || ! $flow->capability_hash
                || ! hash_equals($flow->capability_hash, hash('sha256', $factor->pending_secret_ciphertext)) || CarbonImmutable::parse($factor->pending_expires_at)->lessThanOrEqualTo($this->now())
                || ($factor->confirmed_at && $flow->action !== 'replace_factor')
                || $factor->pending_key_version !== config('mfa.key_version')) {
                return null;
            }
            $step = $this->step($this->codec->decryptTotp($factor->pending_secret_ciphertext), $code);
            // AUTH-2 watermark незменшуваний навіть при заміні фактора.
            if ($step === null || ($factor->last_used_step !== null && $step <= $factor->last_used_step)) {
                return null;
            }
            DB::table('user_totp_factors')->where('user_id', $user->id)->update([
                'secret_ciphertext' => $factor->pending_secret_ciphertext, 'key_version' => $factor->pending_key_version,
                'confirmed_at' => $this->stamp(), 'last_used_step' => $step,
                'pending_secret_ciphertext' => null, 'pending_key_version' => null, 'pending_expires_at' => null,
                'updated_at' => $this->stamp(),
            ]);
            $this->installBatch($user->id, $batch, $rows);
            $this->complete($flow);
            $this->revoke($user->id);
            $this->audit($user->id, 'mfa.factor_activated');

            return new SecretValue($codes);
        });
    }

    /** Результат після MFA commit — лише внутрішній одноразовий proof, не HTTP API. */
    public function finishLogin(string $flowId, #[\SensitiveParameter] string $binding, #[\SensitiveParameter] string $code, bool $recovery = false): MfaProof
    {
        $precheck = $recovery ? $this->recoveryPrecheck($code) : null;

        return $this->withFlow($flowId, $binding, function ($user, $flow, $factor) use ($code, $recovery, $precheck) {
            if ($flow->purpose !== 'login' || $flow->action !== null || ! $factor?->confirmed_at
                || ! $this->consume($factor, $code, $recovery, $precheck)) {
                return null;
            }
            $this->complete($flow);
            if ($recovery) {
                $this->revoke($user->id);
            }
            $this->audit($user->id, $recovery ? 'mfa.recovery_used' : 'mfa.login_completed');

            return new MfaProof($user->id, $flow->audience, $user->auth_version + ($recovery ? 1 : 0),
                $flow->audience === 'admin' ? 'password' : 'email_otp',
                $flow->primary_verified_at, $this->stamp(), $recovery ? 'recovery_code' : 'totp');
        });
    }

    /** Заміна/ротація: новий парольний primary flow + чинний другий фактор. */
    public function replaceFactor(string $flowId, #[\SensitiveParameter] string $binding, #[\SensitiveParameter] string $code, bool $recovery = false): string
    {
        $precheck = $recovery ? $this->recoveryPrecheck($code) : null;

        return $this->withFlow($flowId, $binding, function ($user, $flow, $factor) use ($code, $recovery, $precheck) {
            if ($flow->audience !== 'admin' || $flow->purpose !== 'login' || $flow->action !== null
                || ! $factor?->confirmed_at || ! $this->consume($factor, $code, $recovery, $precheck)) {
                return null;
            }
            $this->complete($flow);
            $this->revoke($user->id);
            $id = (string) Str::uuid();
            $now = $this->now();
            DB::table('auth_flows')->insert([
                'public_id' => $id, 'browser_binding_hash' => $flow->browser_binding_hash,
                'audience' => 'admin', 'purpose' => 'mfa_enroll', 'action' => 'replace_factor',
                'user_id' => $user->id, 'auth_version_at_start' => $user->auth_version + 1,
                'state' => 'primary_verified', 'primary_verified_at' => $now->format('Y-m-d H:i:s.uP'),
                'created_at' => $now->format('Y-m-d H:i:s.uP'), 'expires_at' => $flow->expires_at,
            ]);
            $this->audit($user->id, 'mfa.replacement_authorized');

            return $id;
        });
    }

    public function rotateRecoveryCodes(string $flowId, #[\SensitiveParameter] string $binding, #[\SensitiveParameter] string $totp): SecretValue
    {
        [$batch, $codes, $rows] = $this->newRecoveryBatch();

        return $this->withFlow($flowId, $binding, function ($user, $flow, $factor) use ($totp, $batch, $codes, $rows) {
            if ($flow->purpose !== 'login' || $flow->action !== null || ! $factor?->confirmed_at || ! $this->consume($factor, $totp, false, null)) {
                return null;
            }
            $this->installBatch($user->id, $batch, $rows);
            $this->complete($flow);
            $this->revoke($user->id);
            $this->audit($user->id, 'mfa.recovery_rotated');

            return new SecretValue($codes);
        });
    }

    public function disableFactor(int $userId): never
    {
        // Жодного admin disable або чужого reset; optional client management — AUTH-5.
        throw new MfaDenied;
    }

    public function logoutAll(string $flowId, #[\SensitiveParameter] string $binding, #[\SensitiveParameter] string $code, bool $recovery = false): void
    {
        $precheck = $recovery ? $this->recoveryPrecheck($code) : null;
        $this->withFlow($flowId, $binding, function ($user, $flow, $factor) use ($code, $recovery, $precheck) {
            if ($flow->purpose !== 'login' || $flow->action !== null || ! $factor?->confirmed_at
                || ! $this->consume($factor, $code, $recovery, $precheck)) {
                return null;
            }
            $this->complete($flow);
            $this->revoke($user->id);
            $this->audit($user->id, 'mfa.logout_all');

            return true;
        });
    }

    private function withFlow(string $id, string $binding, callable $operation): mixed
    {
        $this->policy->assertControlled();
        if (! Str::isUuid($id)) {
            throw new MfaDenied;
        }
        // Попереднє читання ID — не авторизація; повторна перевірка після user lock.
        $userId = DB::table('auth_flows')->where('public_id', $id)->value('user_id');
        if (! $userId) {
            throw new MfaDenied;
        }
        $result = DB::transaction(function () use ($id, $binding, $operation, $userId) {
            $user = $this->user($userId);
            $flow = DB::table('auth_flows')->where('public_id', $id)->lockForUpdate()->first();
            $this->budget($userId);
            if (! $flow || $flow->user_id !== $user->id || $flow->state !== 'primary_verified'
                || ! hash_equals($flow->browser_binding_hash, hash('sha256', $binding))
                || CarbonImmutable::parse($flow->expires_at)->lessThanOrEqualTo($this->now())
                || $flow->auth_version_at_start !== $user->auth_version
                || ! in_array($flow->purpose, ['login', 'mfa_enroll'], true)
                || ($flow->audience === 'admin' && $user->role !== 'admin')
                || ($flow->audience === 'client' && $user->role !== 'client')) {
                $this->audit($userId, 'mfa.denied', 'denied');

                return null;
            }
            $factor = DB::table('user_totp_factors')->where('user_id', $userId)->lockForUpdate()->first();
            $result = $operation($user, $flow, $factor);
            if ($result === null) {
                $this->audit($userId, 'mfa.denied', 'denied');
            }

            return $result;
        });
        if ($result === null) {
            throw new MfaDenied;
        }

        return $result;
    }

    private function consume(object $factor, string $code, bool $recovery, ?object $precheck): bool
    {
        if ($recovery) {
            if (! $precheck || $precheck->user_id !== $factor->user_id || $precheck->batch_id !== $factor->recovery_batch_id) {
                return false;
            }

            return DB::table('mfa_recovery_codes')->where('id', $precheck->id)
                ->where('code_hash', $precheck->code_hash)->where('batch_id', $factor->recovery_batch_id)
                ->whereNull('used_at')->whereNull('revoked_at')->update(['used_at' => $this->stamp()]) === 1;
        }
        if ($factor->key_version !== config('mfa.key_version')) {
            return false;
        }
        $step = $this->step($this->codec->decryptTotp($factor->secret_ciphertext), $code);
        if ($step === null) {
            return false;
        }

        return DB::table('user_totp_factors')->where('user_id', $factor->user_id)
            ->where(fn ($q) => $q->whereNull('last_used_step')->orWhere('last_used_step', '<', $step))
            ->update(['last_used_step' => $step, 'updated_at' => $this->stamp()]) === 1;
    }

    private function step(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code): ?int
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }
        $time = intdiv($this->now()->timestamp, 30);
        $window = (int) config('mfa.window');
        // Новіший із допустимих steps; один watermark для всіх audiences і факторів.
        for ($step = $time + $window; $step >= $time - $window; $step--) {
            if (hash_equals((new Google2FA)->oathTotp($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private function recoveryPrecheck(#[\SensitiveParameter] string $code): ?object
    {
        $this->policy->assertControlled();
        $parts = explode('.', $code, 2);
        if (count($parts) !== 2 || ! Str::isUuid($parts[0]) || ! preg_match('/^[a-f0-9]{40}$/D', $parts[1])) {
            return null;
        }
        $row = DB::table('mfa_recovery_codes')->where('selector', $parts[0])->first();

        return $row && Hash::driver('argon2id')->check($parts[1], $row->code_hash) ? $row : null;
    }

    private function newRecoveryBatch(): array
    {
        $this->policy->assertControlled();
        $batch = (string) Str::uuid();
        $codes = $rows = [];
        for ($i = 0; $i < (int) config('mfa.recovery_count'); $i++) {
            $selector = (string) Str::uuid();
            $code = bin2hex(random_bytes(20));
            $codes[] = $selector.'.'.$code;
            $rows[] = ['selector' => $selector, 'code_hash' => $this->codec->hashRecoveryCode($code), 'batch_id' => $batch];
        }

        return [$batch, $codes, $rows];
    }

    private function installBatch(int $userId, string $batch, array $rows): void
    {
        DB::table('mfa_recovery_codes')->where('user_id', $userId)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => $this->stamp()]);
        DB::table('user_totp_factors')->where('user_id', $userId)->update(['recovery_batch_id' => $batch]);
        foreach ($rows as $row) {
            DB::table('mfa_recovery_codes')->insert($row + ['user_id' => $userId]);
        }
    }

    private function complete(object $flow): void
    {
        if (DB::table('auth_flows')->where('id', $flow->id)->where('state', 'primary_verified')
            ->whereRaw('expires_at > clock_timestamp()')->update(['state' => 'completed', 'consumed_at' => $this->stamp()]) !== 1) {
            throw new MfaDenied;
        }
    }

    private function user(int $id): object
    {
        return DB::table('users')->where('id', $id)->lockForUpdate()->first() ?? throw new MfaDenied;
    }

    private function invalidateFlows(int $userId, ?string $binding = null): void
    {
        DB::table('auth_flows')->where('user_id', $userId)->whereIn('state', ['pending', 'primary_verified'])
            ->when($binding !== null, fn ($q) => $q->where('browser_binding_hash', hash('sha256', $binding)))
            ->update(['state' => 'invalidated', 'invalidated_at' => $this->stamp()]);
    }

    private function revoke(int $id): void
    {
        DB::table('users')->where('id', $id)->increment('auth_version');
        $this->invalidateFlows($id);
        DB::table('auth_session_states')->where('user_id', $id)->whereNull('revoked_at')->update(['revoked_at' => $this->stamp()]);
    }

    private function budget(int $id): void
    {
        if (DB::table('auth_audit_events')->where('subject_user_id', $id)->where('outcome', 'denied')
            ->where('occurred_at', '>', $this->now()->subSeconds((int) config('mfa.flow_seconds')))->count() >= (int) config('mfa.attempts')) {
            throw new MfaDenied;
        }
    }

    private function audit(int $id, string $event, string $outcome = 'success'): void
    {
        DB::table('auth_audit_events')->insert(['actor_user_id' => $id, 'subject_user_id' => $id,
            'event_type' => $event, 'outcome' => $outcome, 'request_id' => (string) Str::uuid()]);
    }

    private function stamp(): string
    {
        return $this->now()->format('Y-m-d H:i:s.uP');
    }

    private function now(): CarbonImmutable
    {
        // PG wall clock, не початок транзакції й не browser clock.
        return CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS t')->t);
    }
}
