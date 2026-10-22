<?php

namespace App\Services\Auth\Client;

use App\Services\Auth\MfaService;
use App\Services\Auth\Notices\NoticeType;
use App\Services\Auth\Notices\NoticeWriter;
use App\Services\Auth\SecretValue;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientSecurity
{
    public const ACTIONS = ['enable_factor', 'replace_factor', 'disable_factor', 'rotate_recovery', 'revoke_session', 'logout_all', 'email_change'];

    public function __construct(private ControlledAuth $policy, private ClientSession $sessions, private ClientAuth $auth, private MfaService $mfa, private EmailNormalizer $emails) {}

    public function requestReauth(Request $request, string $action, ?string $flow = null): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new AuthDenied('request_denied', 403);
        }
        [$user, $context] = $this->policy->transaction(function () use ($request, $action) {
            $user = $this->sessions->user($request);

            return [$user, $this->context($request, $user, $action)];
        });

        return $this->auth->request('reauth', $user->email, $flow, $this->binding($request), $request->ip(), $context);
    }

    public function verifyReauth(Request $request, string $challenge, #[\SensitiveParameter] string $code): array
    {
        $this->checkChallenge($request, $challenge, 'reauth');
        $result = $this->auth->verify($challenge, $code, $this->binding($request), $request->ip());
        $active = $this->policy->transaction(function () use ($request, $result) {
            $user = $this->sessions->user($request);
            $this->flow($request, $result['flow_id'], $user, 'reauth', 'primary_verified');

            return DB::table('user_totp_factors')->where('user_id', $user->id)->whereNotNull('confirmed_at')->whereNull('disabled_at')->exists();
        });
        if ($active) {
            return ['next' => 'mfa_required', 'flow_id' => $result['flow_id']];
        }

        return $this->authorize($request, $result['flow_id'], '', false);
    }

    public function authorize(Request $request, string $id, #[\SensitiveParameter] string $code, bool $recovery): array
    {
        $this->policy->transaction(function () use ($request, $id) {
            $user = $this->sessions->user($request);
            $this->flow($request, $id, $user, 'reauth', 'primary_verified');
            if (! app(AuthBudget::class)->take('mfa:ip:'.$request->ip(), 20, 900)) {
                throw new AuthDenied('rate_limited', 429);
            }
        });

        // Невдалі MFA спроби повинні комітитися навіть при відмові.
        return $this->mfa->authorizeClientAction($id, $this->binding($request), $this->sessionHash($request), $code, $recovery, function (SecretValue $capability) use ($request, $id, $recovery) {
            // MfaService вже тримає user/flow/factor locks. Не брати global lock після user.
            $token = $capability->reveal();
            $user = $this->sessions->user($request);
            $flow = $this->flow($request, $id, $user, 'reauth', 'primary_verified');
            if (! hash_equals($flow->capability_hash ?? '', hash('sha256', $token))) {
                throw new AuthDenied;
            }
            // Перенесення proof у нову session, без подовження absolute або primary TTL.
            DB::table('auth_flows')->where('id', $flow->id)->update(['capability_hash' => null, 'state' => 'completed', 'consumed_at' => $this->policy->stamp()]);
            $this->sessions->rotate($request, $recovery);
            DB::table('auth_flows')->where('user_id', $user->id)->where('audience', 'client')
                ->when(! $recovery, fn ($query) => $query->where('browser_binding_hash', hash('sha256', $this->binding($request))))
                ->whereIn('state', ['pending', 'primary_verified'])->update(['state' => 'invalidated', 'invalidated_at' => $this->policy->stamp()]);
            $proofId = (string) Str::uuid();
            DB::table('auth_flows')->insert([
                'public_id' => $proofId, 'browser_binding_hash' => hash('sha256', $this->binding($request)),
                'session_binding_hash' => $this->sessionHash($request), 'audience' => 'client', 'purpose' => 'reauth',
                'user_id' => $user->id, 'auth_version_at_start' => $user->auth_version + ($recovery ? 1 : 0),
                'state' => 'primary_verified', 'action' => $flow->action, 'capability_hash' => hash('sha256', $token),
                'created_at' => $flow->created_at, 'primary_verified_at' => $flow->primary_verified_at, 'expires_at' => $flow->expires_at,
            ]);
            $request->session()->forget('auth5_proofs');
            $request->session()->put('auth5_proofs.'.$proofId, $token);

            return ['next' => 'authorized', 'flow_id' => $proofId];
        });
    }

    public function enrollment(Request $request, string $proof, bool $replace): array
    {
        return $this->policy->transaction(function () use ($request, $proof, $replace) {
            [$user, $grant] = $this->consume($request, $proof, $replace ? 'replace_factor' : 'enable_factor');
            $active = DB::table('user_totp_factors')->where('user_id', $user->id)->whereNotNull('confirmed_at')->whereNull('disabled_at')->exists();
            if ($active !== $replace) {
                throw new AuthDenied;
            }
            $id = $this->mfaFlow($request, $user, $grant, $replace ? 'replace_factor' : null);
            $secret = $this->mfa->beginEnrollment($id, $this->binding($request))->reveal();

            return ['flow_id' => $id, 'secret' => new SecretValue($secret), 'account' => $user->email];
        });
    }

    public function confirmEnrollment(Request $request, string $id, #[\SensitiveParameter] string $code): SecretValue
    {
        $this->policy->transaction(function () use ($request, $id) {
            $user = $this->sessions->user($request);
            $this->flow($request, $id, $user, 'mfa_enroll', 'primary_verified');
        });
        // MfaService повторно блокує user/version/flow/factor й комітить невдалі спроби.
        $codes = $this->mfa->confirmEnrollment($id, $this->binding($request), $code);
        $this->sessions->logout($request);

        return $codes;
    }

    public function rotateRecovery(Request $request, string $proof): SecretValue
    {
        $this->policy->transaction(fn () => $this->consume($request, $proof, 'rotate_recovery', false));
        // Argon2id поза global/user locks; proof повторно перевіряється перед commit.
        $prepared = $this->mfa->prepareRecoveryRotation();

        return $this->policy->transaction(function () use ($request, $proof, $prepared) {
            [$user, $grant] = $this->consume($request, $proof, 'rotate_recovery');
            $codes = $this->mfa->rotateRecoveryCodes($this->mfaFlow($request, $user, $grant, 'rotate_recovery'), $this->binding($request), '', $prepared);
            $this->sessions->logout($request);

            return $codes;
        });
    }

    public function disable(Request $request, string $proof): void
    {
        $this->policy->transaction(function () use ($request, $proof) {
            [$user, $grant] = $this->consume($request, $proof, 'disable_factor');
            $this->mfa->disableClientFactor($this->mfaFlow($request, $user, $grant, 'disable_factor'), $this->binding($request));
            $this->sessions->logout($request);
        });
    }

    public function sessions(Request $request): array
    {
        return $this->policy->transaction(function () use ($request) {
            $user = $this->sessions->user($request);

            return $this->ownSessions($user)->map(fn ($row) => [
                'handle' => $this->handle($user, $row->session_id), 'current' => $row->session_id === $request->session()->getId(),
                'authenticated_at' => $row->primary_verified_at, 'last_active_at' => gmdate('c', $row->last_activity),
            ])->all();
        });
    }

    public function revokeSession(Request $request, string $proof, string $handle): void
    {
        $current = $this->policy->transaction(function () use ($request, $proof, $handle) {
            [$user] = $this->consume($request, $proof, 'revoke_session');
            // Handle прив'язаний до власника/version, сирі session IDs не виходять із сервера.
            $target = $this->ownSessions($user)->first(fn ($row) => hash_equals($this->handle($user, $row->session_id), $handle));
            if (! $target) {
                throw new AuthDenied('request_denied', 403);
            }
            DB::table('auth_session_states')->where('session_id', $target->session_id)->whereNull('revoked_at')->update(['revoked_at' => $this->policy->stamp()]);
            $this->policy->audit('client.session_revoked', $user->id);

            return $target->session_id === $request->session()->getId();
        });
        if ($current) {
            $this->sessions->logout($request);
        }
    }

    public function logoutAll(Request $request, string $proof): void
    {
        $this->policy->transaction(function () use ($request, $proof) {
            [$user] = $this->consume($request, $proof, 'logout_all');
            $this->revokeAll($user->id);
            $this->policy->audit('client.logout_all', $user->id);
            $this->sessions->logout($request);
        });
    }

    public function requestEmail(Request $request, ?string $proof, string $email, ?string $id = null): array
    {
        $normalized = $this->emails->normalize($email);
        $context = $this->policy->transaction(function () use ($request, $proof, $normalized, $id) {
            if ($id) {
                $user = $this->sessions->user($request);
                $flow = $this->flow($request, $id, $user, 'email_change', 'pending');
                $existing = $request->session()->get('auth5_email.'.$id);
                if (! is_string($existing) || $this->emails->normalize($existing) !== $normalized || $flow->action !== 'email_change') {
                    throw new AuthDenied;
                }
            } else {
                [$user] = $this->consume($request, (string) $proof, 'email_change');
            }
            if ($normalized === $user->email_normalized) {
                throw new AuthDenied('email_conflict', 409);
            }

            return $this->context($request, $user, 'email_change');
        });
        $result = $this->auth->request('email_change', $email, $id, $this->binding($request), $request->ip(), $context);
        // Flow ID не є authority; зберігаємо delivery адресу тільки серверно.
        $flow = DB::table('auth_challenges as c')->join('auth_flows as f', 'f.id', '=', 'c.flow_id')
            ->where('c.public_id', $result['challenge_id'])->value('f.public_id');
        if ($flow) {
            $request->session()->put('auth5_email.'.$flow, trim($email));
        }

        return $result + ['flow_id' => $flow ?? $id];
    }

    public function confirmEmail(Request $request, string $challenge, #[\SensitiveParameter] string $code): void
    {
        $this->checkChallenge($request, $challenge, 'email_change');
        $result = $this->auth->verify($challenge, $code, $this->binding($request), $request->ip());
        $correlation = (string) Str::uuid();
        $this->policy->transaction(function () use ($request, $result, $correlation) {
            $user = $this->sessions->user($request);
            $flow = $this->flow($request, $result['flow_id'], $user, 'email_change', 'primary_verified');
            $email = (string) $request->session()->get('auth5_email.'.$flow->public_id);
            $normalized = $this->emails->normalize($email);
            $challenge = DB::table('auth_challenges')->where('flow_id', $flow->id)->where('generation', $flow->generation)->first();
            if (! $challenge?->consumed_at || $challenge->email_normalized !== $normalized) {
                throw new AuthDenied;
            }
            // Включно з історичними NULL canonical keys. Колізії не об'єднують акаунти.
            foreach (DB::table('users')->whereNotNull('email')->where('id', '<>', $user->id)->get(['email', 'email_normalized']) as $other) {
                $key = $this->emails->normalize($other->email);
                if ($key === $normalized || ($other->email_normalized !== null && $key !== $other->email_normalized)) {
                    throw new AuthDenied('email_conflict', 409);
                }
            }
            DB::table('users')->where('id', $user->id)->update(['email' => $email, 'email_normalized' => $normalized, 'email_verified_at' => $this->policy->stamp()]);
            $this->revokeAll($user->id);
            $this->policy->audit('client.email_changed', $user->id, correlation: $correlation);
            $this->sessions->logout($request);

            app(NoticeWriter::class)->write($user->id, NoticeType::EmailChanged, ['old' => $user->email], request: $correlation);
        });
    }

    private function consume(Request $request, string $id, string $action, bool $consume = true): array
    {
        $user = $this->sessions->user($request);
        $flow = $this->flow($request, $id, $user, 'reauth', 'primary_verified');
        $token = $request->session()->get('auth5_proofs.'.$id);
        if ($flow->action !== $action || ! is_string($token) || ! hash_equals($flow->capability_hash ?? '', hash('sha256', $token))
            || CarbonImmutable::parse($flow->primary_verified_at)->addSeconds(300)->lessThanOrEqualTo($this->policy->now())) {
            throw new AuthDenied('reauth_required', 403);
        }
        if ($consume) {
            DB::table('auth_flows')->where('id', $flow->id)->update(['state' => 'completed', 'consumed_at' => $this->policy->stamp()]);
            $request->session()->forget('auth5_proofs.'.$id);
        }

        return [$user, $flow];
    }

    private function flow(Request $request, string $id, object $user, string $purpose, string $state): object
    {
        $flow = Str::isUuid($id) ? DB::table('auth_flows')->where('public_id', $id)->lockForUpdate()->first() : null;
        if (! $flow || $flow->user_id !== $user->id || $flow->auth_version_at_start !== $user->auth_version
            || $flow->audience !== 'client' || $flow->purpose !== $purpose || $flow->state !== $state
            || $flow->session_binding_hash !== $this->sessionHash($request)
            || $flow->browser_binding_hash !== hash('sha256', $this->binding($request))
            || CarbonImmutable::parse($flow->expires_at)->lessThanOrEqualTo($this->policy->now())) {
            throw new AuthDenied('reauth_required', 403);
        }

        return $flow;
    }

    private function checkChallenge(Request $request, string $id, string $purpose): void
    {
        $this->policy->transaction(function () use ($request, $id, $purpose) {
            $user = $this->sessions->user($request);
            $flow = Str::isUuid($id) ? DB::table('auth_challenges as c')->join('auth_flows as f', 'f.id', '=', 'c.flow_id')->where('c.public_id', $id)->value('f.public_id') : null;
            $this->flow($request, (string) $flow, $user, $purpose, 'pending');
        });
    }

    private function mfaFlow(Request $request, object $user, object $grant, ?string $action): string
    {
        DB::table('auth_flows')->where('user_id', $user->id)->where('audience', 'client')->where('purpose', 'mfa_enroll')
            ->whereIn('state', ['pending', 'primary_verified'])->update(['state' => 'invalidated', 'invalidated_at' => $this->policy->stamp()]);
        $id = (string) Str::uuid();
        DB::table('auth_flows')->insert(['public_id' => $id, 'user_id' => $user->id, 'auth_version_at_start' => $user->auth_version,
            'browser_binding_hash' => hash('sha256', $this->binding($request)), 'session_binding_hash' => $this->sessionHash($request),
            'audience' => 'client', 'purpose' => 'mfa_enroll', 'action' => $action, 'state' => 'primary_verified',
            'primary_verified_at' => $grant->primary_verified_at, 'created_at' => $grant->created_at, 'expires_at' => $grant->expires_at]);

        return $id;
    }

    private function revokeAll(int $user): void
    {
        DB::table('users')->where('id', $user)->increment('auth_version');
        $ids = DB::table('auth_flows')->where('user_id', $user)->pluck('id');
        DB::table('auth_challenges')->whereIn('flow_id', $ids)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => $this->policy->stamp()]);
        DB::table('auth_flows')->where('user_id', $user)->whereIn('state', ['pending', 'primary_verified'])->update(['state' => 'invalidated', 'invalidated_at' => $this->policy->stamp()]);
        DB::table('auth_session_states')->where('user_id', $user)->whereNull('revoked_at')->update(['revoked_at' => $this->policy->stamp()]);
    }

    private function ownSessions(object $user): Collection
    {
        return DB::table('auth_session_states as a')->join('sessions as s', 's.id', '=', 'a.session_id')
            ->where('a.user_id', $user->id)->where('a.audience', 'client')->where('a.auth_version', $user->auth_version)
            ->whereNull('a.revoked_at')->whereRaw('a.absolute_expires_at > clock_timestamp()')
            ->where('s.last_activity', '>', $this->policy->now()->timestamp - 43200)
            ->orderByDesc('s.last_activity')->get(['a.session_id', 'a.primary_verified_at', 's.last_activity']);
    }

    private function handle(object $user, string $id): string
    {
        return $this->policy->fingerprint('session:'.$user->id.':'.$user->auth_version.':'.$id);
    }

    private function context(Request $request, object $user, string $action): array
    {
        return ['user_id' => $user->id, 'version' => $user->auth_version, 'action' => $action, 'session_hash' => $this->sessionHash($request)];
    }

    private function binding(Request $request): string
    {
        return (string) $request->session()->get('client_binding');
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }
}
