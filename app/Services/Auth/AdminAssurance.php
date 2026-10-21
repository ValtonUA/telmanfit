<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class AdminAssurance
{
    public function __construct(private MfaPolicy $policy) {}

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
            ->join('user_totp_factors AS f', 'f.user_id', '=', 's.user_id')
            ->where('s.session_id', $request->session()->getId())->where('s.user_id', $id)
            ->where('s.audience', 'admin')->where('s.primary_method', 'password')
            ->whereNotNull('s.mfa_verified_at')->whereIn('s.mfa_method', ['totp', 'recovery_code'])
            ->whereNotNull('f.confirmed_at')->whereNull('s.revoked_at')
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
        if ($proof->audience !== 'admin') {
            throw new MfaDenied;
        }
        $proof->claim();
        DB::transaction(function () use ($request, $proof) {
            $user = DB::table('users')->where('id', $proof->user_id)->lockForUpdate()->first();
            if (! $user || $user->role !== 'admin' || $user->auth_version !== $proof->auth_version) {
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
                'absolute_expires_at' => CarbonImmutable::parse($proof->mfa_verified_at)->addSeconds((int) config('mfa.session_seconds'))->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }
}
