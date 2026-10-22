<?php

namespace App\Services\Auth\Client;

use App\Jobs\DeliverEmailOtp;
use App\Services\Auth\SecretValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientAuth
{
    private \WeakMap $proofs;

    public function __construct(private ControlledAuth $auth, private AuthBudget $budget, private EmailNormalizer $emails)
    {
        $this->proofs = new \WeakMap;
    }

    public function exchange(#[\SensitiveParameter] string $token, string $binding, string $ip): array
    {
        $result = $this->auth->transaction(function () use ($token, $binding, $ip) {
            if (! $this->budget->take('exchange:ip:'.$ip, 10, 60)
                || ! $this->budget->take('exchange:token:'.hash('sha256', $token), 5, 900)) {
                return new AuthDenied('rate_limited', 429);
            }
            $invitation = DB::table('client_invitations')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! preg_match('/^[a-f0-9]{64}$/D', $token) || ! $this->available($invitation)) {
                return new AuthDenied('invitation_unavailable');
            }
            $this->invalidate($binding, 'register');
            $flow = $this->newFlow($binding, 'register', null, $invitation->id, 900);

            return ['flow_id' => $flow->public_id, 'expires_at' => $flow->expires_at];
        });

        return $this->result($result);
    }

    /** 202 shape однакова, suppressed/unknown challenges — випадкові UUID без authority. */
    public function request(string $purpose, string $email, ?string $flowId, string $binding, string $ip, ?array $context = null): array
    {
        if (! in_array($purpose, ['register', 'login'], true) && (! $context || ! in_array($purpose, ['reauth', 'email_change'], true))) {
            throw new AuthDenied;
        }
        $normalized = $this->emails->normalize($email);
        $delivery = null;
        $allowed = $this->auth->transaction(fn () => $this->budget->take('issue:ip:15:'.$ip, 20, 900)
            && $this->budget->take('issue:ip:day:'.$ip, 100, 86400) && $this->budget->take('issue:global', 1000, 3600));
        if (! $allowed) {
            throw new AuthDenied('rate_limited', 429);
        }
        $result = $this->auth->transaction(function () use ($purpose, $email, $normalized, $flowId, $binding, $context, &$delivery) {
            $response = ['challenge_id' => (string) Str::uuid(), 'expires_in' => 300, 'resend_after' => 60];
            $delivery = new DeliverEmailOtp($response['challenge_id'], trim($email), sprintf('%08d', random_int(0, 99999999)));
            // Усі budgets спільні для нових flows та resends.
            $allowed = true;
            $limits = [['cooldown:'.$purpose.':'.$normalized, 1, 60], ['issue:15:'.$purpose.':'.$normalized, 3, 900],
                ['issue:day:'.$purpose.':'.$normalized, 10, 86400], ['issue:all:'.$normalized, 15, 86400]];
            foreach ($limits as [$key, $limit, $seconds]) {
                $allowed = $this->budget->allows($key, $limit, $seconds) && $allowed;
            }
            if (! $allowed || $this->budget->blocked($normalized)) {
                return $response;
            }
            foreach ($limits as [$key, $limit, $seconds]) {
                $this->budget->take($key, $limit, $seconds);
            }
            if ($context) {
                $user = DB::table('users')->where('id', $context['user_id'])->lockForUpdate()->first();
                if (! $this->eligible($user, $user?->email_normalized ?? '')
                    || $user->auth_version !== $context['version']
                    || ($purpose === 'reauth' && $normalized !== $user->email_normalized)) {
                    return new AuthDenied('unauthenticated', 401);
                }
                if ($flowId) {
                    $flow = $this->lockedFlow($flowId, $binding, 'pending');
                    if ($flow->purpose !== $purpose || $flow->user_id !== $user->id
                        || $flow->action !== $context['action'] || $flow->session_binding_hash !== $context['session_hash']) {
                        return new AuthDenied;
                    }
                } else {
                    $this->invalidate($binding, $purpose);
                    $flow = $this->newFlow($binding, $purpose, $user, null, 300, $context);
                }
            } elseif ($purpose === 'register') {
                $flow = $this->lockedFlow($flowId, $binding, 'pending');
                if ($flow->purpose !== 'register') {
                    return new AuthDenied;
                }
            } else {
                $candidate = DB::table('users')->where('email_normalized', $normalized)->first();
                if (! $this->eligible($candidate, $normalized)) {
                    return $response;
                }
                $user = DB::table('users')->where('id', $candidate->id)->lockForUpdate()->first();
                if (! $this->eligible($user, $normalized)) {
                    return $response;
                }
                if ($flowId) {
                    try {
                        $flow = $this->lockedFlow($flowId, $binding, 'pending');
                    } catch (AuthDenied $e) {
                        // Невалідний login resend має той самий decoy/enqueue contract.
                        if ($e->status !== 422) {
                            throw $e;
                        }

                        return $response;
                    }
                    if ($flow->purpose !== 'login' || $flow->user_id !== $user->id) {
                        return $response;
                    }
                } else {
                    $this->invalidate($binding, 'login');
                    $flow = $this->newFlow($binding, 'login', $user, null, 300);
                }
            }
            if (DB::table('auth_challenges')->where('flow_id', $flow->id)->sum('attempts') >= 5) {
                return $response;
            }
            $now = $this->auth->stamp();
            DB::table('auth_challenges')->where('flow_id', $flow->id)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => $now]);
            $generation = $flow->generation + 1;
            DB::table('auth_flows')->where('id', $flow->id)->update(['generation' => $generation]);
            $flow->generation = $generation;
            $code = sprintf('%08d', random_int(0, 99999999));
            $version = (string) config('auth4.key_version');
            DB::table('auth_challenges')->insert([
                'public_id' => $response['challenge_id'], 'flow_id' => $flow->id, 'generation' => $generation,
                'email_normalized' => $normalized, 'code_mac' => $this->mac($flow, $response['challenge_id'], $normalized, $code, $version),
                'key_version' => $version, 'max_attempts' => 5, 'created_at' => $now,
                'expires_at' => $this->auth->now()->addSeconds(300)->format('Y-m-d H:i:s.uP'),
            ]);
            $delivery = new DeliverEmailOtp($response['challenge_id'], trim($email), $code);

            return $response;
        });
        $response = $this->result($result);
        if ($delivery) {
            try {
                // Жодної пошти/мережі під PG locks.
                dispatch($delivery);
            } catch (\Throwable) {
                $this->auth->transaction(fn () => DB::table('auth_challenges')->where('public_id', $response['challenge_id'])
                    ->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => $this->auth->stamp()]));
                throw new AuthDenied('auth_unavailable', 503);
            }
        }

        return $response;
    }

    public function verify(string $challengeId, #[\SensitiveParameter] string $code, string $binding, string $ip): array
    {
        if (! $this->auth->transaction(fn () => $this->budget->take('verify:ip:'.$ip, 10, 60))) {
            throw new AuthDenied('rate_limited', 429);
        }
        $result = $this->auth->transaction(function () use ($challengeId, $code, $binding) {
            $candidate = Str::isUuid($challengeId) ? DB::table('auth_challenges')->where('public_id', $challengeId)->first() : null;
            if (! $candidate) {
                return new AuthDenied;
            }
            $flowId = DB::table('auth_flows')->where('id', $candidate->flow_id)->value('public_id');
            $flow = $this->lockedFlow($flowId, $binding, 'pending');
            $challenge = DB::table('auth_challenges')->where('id', $candidate->id)->lockForUpdate()->first();
            if ($this->budget->blocked($challenge->email_normalized)) {
                // Account-only pause не відрізняє real challenge від neutral decoy.
                return new AuthDenied;
            }
            if ($challenge->consumed_at || $challenge->invalidated_at || $challenge->generation !== $flow->generation
                || CarbonImmutable::parse($challenge->expires_at)->lessThanOrEqualTo($this->auth->now())
                || $challenge->attempts >= 5 || DB::table('auth_challenges')->where('flow_id', $flow->id)->sum('attempts') >= 5) {
                return new AuthDenied;
            }
            if ($flow->user_id) {
                $user = DB::table('users')->where('id', $flow->user_id)->first();
                if (! $this->eligible($user, $flow->purpose === 'email_change' ? $user->email_normalized : $challenge->email_normalized)) {
                    return new AuthDenied;
                }
            }
            $mac = $this->mac($flow, $challengeId, $challenge->email_normalized, $code, $challenge->key_version);
            if (! preg_match('/^[0-9]{8}$/D', $code) || ! hash_equals($challenge->code_mac, $mac)) {
                DB::table('auth_challenges')->where('id', $challenge->id)->increment('attempts');
                $this->budget->failure($challenge->email_normalized);

                return new AuthDenied;
            }
            if (DB::table('auth_challenges')->where('id', $challenge->id)->whereNull('consumed_at')->whereNull('invalidated_at')
                ->whereRaw('expires_at > clock_timestamp() AND attempts < max_attempts')->update(['consumed_at' => $this->auth->stamp()]) !== 1) {
                return new AuthDenied;
            }
            $stamp = $this->auth->stamp();
            DB::table('auth_flows')->where('id', $flow->id)->update(['state' => 'primary_verified', 'primary_verified_at' => $stamp]);
            if ($flow->purpose === 'register') {
                return ['next' => 'registration_complete', 'flow_id' => $flow->public_id];
            }
            if (in_array($flow->purpose, ['reauth', 'email_change'], true)) {
                return ['next' => $flow->purpose.'_verified', 'flow_id' => $flow->public_id];
            }
            if ($flow->purpose !== 'login') {
                throw new AuthDenied;
            }
            if (DB::table('user_totp_factors')->where('user_id', $flow->user_id)->whereNotNull('confirmed_at')->whereNull('disabled_at')->exists()) {
                return ['next' => 'mfa_required', 'flow_id' => $flow->public_id];
            }
            DB::table('auth_flows')->where('id', $flow->id)->update(['state' => 'completed', 'consumed_at' => $this->auth->stamp()]);
            $this->auth->audit('client.login_completed', $flow->user_id);

            return ['next' => 'authenticated', 'proof' => $this->proof($flow->user_id, $flow->auth_version_at_start, $stamp, $flow->public_id)];
        });

        return $this->result($result);
    }

    public function complete(string $id, string $name, string $deliveryEmail, string $binding, string $ip): SecretValue
    {
        $allowed = $this->auth->transaction(fn () => $this->budget->take('complete:ip:'.$ip, 10, 60) && $this->budget->take('complete:flow:'.$id, 5, 900));
        if (! $allowed) {
            throw new AuthDenied('rate_limited', 429);
        }
        $result = $this->auth->transaction(function () use ($id, $name, $deliveryEmail, $binding) {
            $flow = $this->lockedFlow($id, $binding, 'primary_verified');
            if ($flow->purpose !== 'register' || CarbonImmutable::parse($flow->primary_verified_at)->addSeconds(300)->lessThanOrEqualTo($this->auth->now())) {
                return new AuthDenied;
            }
            $challenge = DB::table('auth_challenges')->where('flow_id', $flow->id)->where('generation', $flow->generation)->lockForUpdate()->first();
            $normalized = $this->emails->normalize($deliveryEmail);
            if (! $challenge?->consumed_at || $challenge->email_normalized !== $normalized) {
                return new AuthDenied;
            }
            // NULL normalized історичного email також резервує адресу. Fail-closed на invalid/collisions.
            $seen = [];
            foreach (DB::table('users')->whereNotNull('email')->get(['email', 'email_normalized']) as $old) {
                try {
                    $key = $this->emails->normalize($old->email);
                } catch (AuthDenied) {
                    return new AuthDenied('registration_conflict', 409);
                }
                if (isset($seen[$key]) || $key === $normalized || ($old->email_normalized !== null && $old->email_normalized !== $key)) {
                    return new AuthDenied('registration_conflict', 409);
                }
                $seen[$key] = true;
            }
            $invitation = DB::table('client_invitations')->where('id', $flow->invitation_id)->first();
            if (! DB::table('users')->where('id', $invitation->coach_user_id)->whereIn('role', ['admin', 'coach'])->exists()) {
                return new AuthDenied('invitation_unavailable');
            }
            $user = DB::table('users')->insertGetId(['name' => $name, 'email' => trim($deliveryEmail), 'email_normalized' => $normalized,
                'email_verified_at' => $this->auth->stamp(), 'role' => 'client', 'password' => null]);
            DB::table('clients')->insert(['user_id' => $user, 'coach_user_id' => $invitation->coach_user_id, 'status' => 'pending', 'coaching_started_at' => null]);
            if (DB::table('client_invitations')->where('id', $invitation->id)->whereNull('consumed_at')->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhereRaw('expires_at > clock_timestamp()'))
                ->update(['consumed_at' => $this->auth->stamp(), 'consumed_by_user_id' => $user]) !== 1) {
                throw new AuthDenied('invitation_unavailable');
            }
            DB::table('auth_flows')->where('id', $flow->id)->update(['state' => 'completed', 'consumed_at' => $this->auth->stamp()]);
            $this->auth->audit('client.registered', $user);

            return $this->proof($user, 0, $flow->primary_verified_at, $flow->public_id);
        });

        return $this->result($result);
    }

    public function claim(SecretValue $proof): array
    {
        if (! isset($this->proofs[$proof])) {
            throw new AuthDenied;
        }
        $value = $this->proofs[$proof];
        unset($this->proofs[$proof]);

        return $value;
    }

    private function proof(int $user, int $version, string $stamp, string $flow): SecretValue
    {
        $proof = new SecretValue('[internal proof]');
        $this->proofs[$proof] = [$user, $version, $stamp, $flow];

        return $proof;
    }

    private function lockedFlow(?string $id, string $binding, string $state): object
    {
        $candidate = $id && Str::isUuid($id) ? DB::table('auth_flows')->where('public_id', $id)->first() : null;
        if (! $candidate) {
            throw new AuthDenied;
        }
        $user = $candidate->user_id ? DB::table('users')->where('id', $candidate->user_id)->lockForUpdate()->first() : null;
        if ($candidate->invitation_id) {
            $coach = DB::table('client_invitations')->where('id', $candidate->invitation_id)->value('coach_user_id');
            if ($coach) {
                DB::table('users')->where('id', $coach)->lockForUpdate()->first();
            }
            $invite = DB::table('client_invitations')->where('id', $candidate->invitation_id)->lockForUpdate()->first();
            if (! $this->available($invite)) {
                throw new AuthDenied('invitation_unavailable');
            }
        }
        $flow = DB::table('auth_flows')->where('id', $candidate->id)->lockForUpdate()->first();
        if ($flow->audience !== 'client' || ! in_array($flow->purpose, ['register', 'login', 'reauth', 'email_change'], true) || $flow->state !== $state
            || ! hash_equals($flow->browser_binding_hash, hash('sha256', $binding))
            || CarbonImmutable::parse($flow->expires_at)->lessThanOrEqualTo($this->auth->now())
            || ($user && ($user->role !== 'client' || $user->auth_version !== $flow->auth_version_at_start
                || ! $user->email_verified_at || ! DB::table('clients')->where('user_id', $user->id)->exists()))) {
            throw new AuthDenied;
        }

        return $flow;
    }

    private function eligible(?object $user, string $normalized): bool
    {
        try {
            if (! $user || $user->role !== 'client' || ! $user->email_verified_at
                || $user->email_normalized !== $normalized || $this->emails->normalize($user->email) !== $normalized
                || ! DB::table('clients')->where('user_id', $user->id)->exists()) {
                return false;
            }
            $matches = 0;
            foreach (DB::table('users')->whereNotNull('email')->get(['email']) as $existing) {
                // Історичний NULL canonical key не дозволяє вибрати переможця колізії.
                if ($this->emails->normalize($existing->email) === $normalized) {
                    $matches++;
                }
            }

            return $matches === 1;
        } catch (AuthDenied) {
            return false;
        }
    }

    private function available(?object $invite): bool
    {
        return $invite && ! $invite->revoked_at && ! $invite->consumed_at && $invite->target_client_id === null
            && ($invite->expires_at === null || CarbonImmutable::parse($invite->expires_at)->greaterThan($this->auth->now()));
    }

    private function invalidate(string $binding, string $purpose): void
    {
        DB::table('auth_flows')->where('browser_binding_hash', hash('sha256', $binding))->where('audience', 'client')->where('purpose', $purpose)
            ->whereIn('state', ['pending', 'primary_verified'])->update(['state' => 'invalidated', 'invalidated_at' => $this->auth->stamp()]);
    }

    private function newFlow(string $binding, string $purpose, ?object $user, ?int $invitation, int $ttl, ?array $context = null): object
    {
        $row = ['public_id' => (string) Str::uuid(), 'browser_binding_hash' => hash('sha256', $binding), 'audience' => 'client',
            'purpose' => $purpose, 'user_id' => $user?->id, 'auth_version_at_start' => $user?->auth_version,
            'invitation_id' => $invitation, 'created_at' => $this->auth->stamp(), 'generation' => 1,
            'expires_at' => $this->auth->now()->addSeconds($ttl)->format('Y-m-d H:i:s.uP')];
        if ($context) {
            $row += ['action' => $context['action'], 'session_binding_hash' => $context['session_hash']];
        }
        $row['id'] = DB::table('auth_flows')->insertGetId($row);

        return (object) $row;
    }

    private function mac(object $flow, string $challenge, string $email, #[\SensitiveParameter] string $code, string $version): string
    {
        return hash_hmac('sha256', json_encode(['email-otp-v1', $version, $flow->public_id, $challenge, $flow->generation,
            $flow->purpose, $flow->audience, $flow->browser_binding_hash, $email, $code], JSON_THROW_ON_ERROR), $this->auth->key($version));
    }

    private function result(mixed $result): mixed
    {
        if ($result instanceof AuthDenied) {
            throw $result;
        }

        return $result;
    }
}
