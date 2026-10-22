<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class AdminAssurance
{
    public function __construct(private MfaPolicy $policy, private MfaService $mfa) {}

    /** Викликається лише під user lock у транзакції бізнес-запиту. */
    public function assert(Request $request): void
    {
        $this->policy->assertControlled();
        $id = Auth::guard('admin')->id();
        if (! $id || ! $request->hasSession() || ! is_string($request->session()->get('mfa_binding'))) {
            throw new MfaDenied;
        }
        $user = DB::table('users')->where('id', $id)->lockForUpdate()->first();
        $state = DB::table('auth_session_states AS s')
            ->join('sessions AS ls', 'ls.id', '=', 's.session_id')
            ->whereRaw('ls.last_activity > EXTRACT(EPOCH FROM clock_timestamp()) - 900')
            ->join('user_totp_factors AS f', 'f.user_id', '=', 's.user_id')
            ->where('s.session_id', $request->session()->getId())->where('s.user_id', $id)
            ->where('s.audience', 'admin')->where('s.primary_method', 'password')
            ->whereNotNull('s.mfa_verified_at')->whereIn('s.mfa_method', ['totp', 'recovery_code'])
            ->whereNotNull('f.confirmed_at')->whereNull('f.disabled_at')->whereNull('s.revoked_at')
            ->whereRaw('s.absolute_expires_at > clock_timestamp()')
            ->where('s.browser_binding_hash', hash('sha256', $request->session()->get('mfa_binding')))
            ->select('s.auth_version')->first();
        if (! $user || $user->role !== 'admin' || ! $state || $state->auth_version !== $user->auth_version) {
            throw new MfaDenied;
        }
    }

    /** Guard та новий session ID видаються лише після committed MFA proof. */
    public function issue(Request $request, MfaProof $proof): void
    {
        $this->policy->assertControlled();
        if (! $request->hasSession() || $proof->audience !== 'admin' || $proof->primary_method !== 'password'
            || ! in_array($proof->mfa_method, ['totp', 'recovery_code'], true)) {
            throw new MfaDenied;
        }
        $flowId = $this->mfa->claimLoginProof($proof);
        DB::transaction(function () use ($request, $proof, $flowId) {
            $user = DB::table('users')->where('id', $proof->user_id)->lockForUpdate()->first();
            if (! $user || $user->role !== 'admin' || $user->auth_version !== $proof->auth_version) {
                throw new MfaDenied;
            }
            $flow = DB::table('auth_flows')->where('public_id', $flowId)->lockForUpdate()->first();
            $factor = DB::table('user_totp_factors')->where('user_id', $user->id)->lockForUpdate()->first();
            $now = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS t')->t);
            if (! $flow || $flow->state !== 'completed' || $flow->purpose !== 'login'
                || $flow->action !== null || $flow->audience !== 'admin' || $flow->user_id !== $user->id
                || $flow->auth_version_at_start + ($proof->mfa_method === 'recovery_code' ? 1 : 0) !== $proof->auth_version
                || ! is_string($request->session()->get('mfa_binding'))
                || ! hash_equals($flow->browser_binding_hash, hash('sha256', $request->session()->get('mfa_binding')))
                || $flow->primary_verified_at !== $proof->primary_verified_at
                || CarbonImmutable::parse($flow->expires_at)->lessThanOrEqualTo($now)
                || ! MfaService::active($factor)
                || CarbonImmutable::parse($proof->mfa_verified_at)->lessThan(CarbonImmutable::parse($flow->consumed_at))
                || CarbonImmutable::parse($proof->mfa_verified_at)->greaterThan($now)) {
                throw new MfaDenied;
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $binding = bin2hex(random_bytes(32));
            $request->session()->put('mfa_binding', $binding);
            Auth::guard('admin')->loginUsingId($proof->user_id, false);
            // Filament AuthenticateSession працює без remember-me.
            $request->session()->put('password_hash_admin', $user->password);
            $request->session()->save();
            DB::table('auth_session_states')->insert([
                'session_id' => $request->session()->getId(), 'user_id' => $user->id,
                'audience' => 'admin', 'browser_binding_hash' => hash('sha256', $binding),
                'auth_version' => $proof->auth_version, 'primary_method' => 'password',
                'primary_verified_at' => $proof->primary_verified_at, 'mfa_verified_at' => $proof->mfa_verified_at,
                'mfa_method' => $proof->mfa_method,
                'absolute_expires_at' => CarbonImmutable::parse($proof->mfa_verified_at)->addHours(8)->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }
}
