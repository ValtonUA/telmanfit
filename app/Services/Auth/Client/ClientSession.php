<?php

namespace App\Services\Auth\Client;

use App\Services\Auth\MfaProof;
use App\Services\Auth\MfaService;
use App\Services\Auth\SecretValue;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ClientSession
{
    public function __construct(private ControlledAuth $auth, private ClientAuth $client, private MfaService $mfa) {}

    public function issue(Request $request, SecretValue|MfaProof $proof): void
    {
        $this->auth->assert();
        if ($proof instanceof MfaProof) {
            if ($proof->audience !== 'client' || $proof->primary_method !== 'email_otp'
                || ! in_array($proof->mfa_method, ['totp', 'recovery_code'], true)) {
                throw new AuthDenied;
            }
            $flowId = $this->mfa->claimLoginProof($proof);
            [$id, $version, $stamp] = [$proof->user_id, $proof->auth_version, $proof->primary_verified_at];
            $mfaAt = $proof->mfa_verified_at;
            $method = $proof->mfa_method;
        } else {
            [$id, $version, $stamp, $flowId] = $this->client->claim($proof);
            $mfaAt = $method = null;
        }
        DB::transaction(function () use ($request, $id, $version, $stamp, $flowId, $mfaAt, $method) {
            $user = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            $flow = DB::table('auth_flows')->where('public_id', $flowId)->lockForUpdate()->first();
            $factor = DB::table('user_totp_factors')->where('user_id', $id)->lockForUpdate()->first();
            if (! $user || $user->role !== 'client' || $user->auth_version !== $version || ! $user->email_verified_at
                || ! DB::table('clients')->where('user_id', $id)->exists() || ! $flow || $flow->state !== 'completed'
                || $flow->audience !== 'client' || ! in_array($flow->purpose, ['register', 'login'], true)
                || ($flow->user_id !== null && $flow->user_id !== $id)
                || ($flow->user_id !== null && $flow->auth_version_at_start + ($method === 'recovery_code' ? 1 : 0) !== $version)
                || $flow->primary_verified_at !== $stamp
                || ! hash_equals($flow->browser_binding_hash, hash('sha256', (string) $request->session()->get('client_binding')))
                || CarbonImmutable::parse($flow->expires_at)->lessThanOrEqualTo($this->auth->now())
                || CarbonImmutable::parse($stamp)->addSeconds(300)->lessThanOrEqualTo($this->auth->now())
                || ($factor?->confirmed_at && ! $mfaAt) || ($mfaAt && ! $factor?->confirmed_at)) {
                throw new AuthDenied;
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $binding = bin2hex(random_bytes(32));
            $request->session()->put('client_binding', $binding);
            Auth::guard('client')->loginUsingId($id, false);
            $request->session()->save();
            DB::table('auth_session_states')->insert(['session_id' => $request->session()->getId(), 'user_id' => $id,
                'audience' => 'client', 'browser_binding_hash' => hash('sha256', $binding), 'auth_version' => $version,
                'primary_method' => 'email_otp', 'primary_verified_at' => $stamp, 'mfa_verified_at' => $mfaAt, 'mfa_method' => $method,
                'absolute_expires_at' => CarbonImmutable::parse($stamp)->addDays(7)->format('Y-m-d H:i:s.uP')]);
        });
    }

    /** Виклик під user lock до завершення protected operation. */
    public function user(Request $request): object
    {
        $this->auth->assert();
        $id = Auth::guard('client')->id();
        $user = $id ? DB::table('users')->where('id', $id)->lockForUpdate()->first() : null;
        $state = DB::table('auth_session_states as a')->join('sessions as s', 's.id', '=', 'a.session_id')
            ->where('a.session_id', $request->session()->getId())->where('a.user_id', $id)->where('a.audience', 'client')
            ->where('a.primary_method', 'email_otp')->whereNull('a.revoked_at')->whereRaw('a.absolute_expires_at > clock_timestamp()')
            ->where('s.last_activity', '>', $this->auth->now()->timestamp - 43200)
            ->where('a.browser_binding_hash', hash('sha256', (string) $request->session()->get('client_binding')))->select('a.*')->first();
        $factor = $id ? DB::table('user_totp_factors')->where('user_id', $id)->whereNotNull('confirmed_at')->exists() : false;
        if (! $user || $user->role !== 'client' || ! $state || $state->auth_version !== $user->auth_version
            || ! $user->email_verified_at || ($factor && (! $state->mfa_verified_at || ! in_array($state->mfa_method, ['totp', 'recovery_code'], true)))) {
            throw new AuthDenied('unauthenticated', 401);
        }

        return $user;
    }

    public function logout(Request $request): void
    {
        $this->auth->transaction(function () use ($request) {
            $binding = (string) $request->session()->get('client_binding');
            DB::table('auth_flows')->where('browser_binding_hash', hash('sha256', $binding))->where('audience', 'client')
                ->whereIn('state', ['pending', 'primary_verified'])->update(['state' => 'invalidated', 'invalidated_at' => $this->auth->stamp()]);
            DB::table('auth_session_states')->where('session_id', $request->session()->getId())->where('audience', 'client')
                ->whereNull('revoked_at')->update(['revoked_at' => $this->auth->stamp()]);
            Auth::guard('client')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        });
    }
}
