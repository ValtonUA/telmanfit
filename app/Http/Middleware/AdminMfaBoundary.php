<?php

namespace App\Http\Middleware;

use App\Services\Auth\Client\AuthBudget;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaPolicy;
use App\Services\Auth\MfaService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Глобальна межа: також default Livewire update/upload/preview та прямі запити. */
final class AdminMfaBoundary
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('mfa.enabled')) {
            return $request->is('admin', 'admin/*', 'livewire*', 'livewire*/*')
                ? app(RequireAdminMfa::class)->handle($request, $next) : $next($request);
        }
        if ($request->is('admin', 'admin/*') || $request->is('livewire*', 'livewire*/*')) {
            try {
                app(MfaPolicy::class)->assertControlled();
            } catch (\Throwable) {
                abort(503, 'Контрольований MFA-стенд недоступний.');
            }
            // Тільки явно перелічені restricted routes; довільні Livewire components заборонені.
            if (in_array($request->route()?->getName(), ['auth3.login', 'auth3.primary', 'auth3.challenge',
                'auth3.enroll', 'auth3.confirm', 'auth3.verify', 'auth3.logout'], true)) {
                return $next($request);
            }

            // Native sensitive forms: password hash перевіряється ДО user lock бізнес-gate.
            if (config('auth4.enabled') && in_array($request->route()?->getName(), ['auth4.invitation.create', 'auth4.invitation.revoke'], true)
                && ! $request->expectsJson()) {
                try {
                    $policy = app(ControlledAuth::class);
                    $id = Auth::guard('admin')->id();
                    $allowed = $policy->transaction(fn () => app(AuthBudget::class)->take('invitation-stepup:ip:'.$request->ip(), 20, 900)
                        && app(AuthBudget::class)->take('invitation-stepup:user:'.$id, 5, 900));
                    $email = $id ? DB::table('users')->where('id', $id)->value('email') : null;
                    if (! $allowed || ! $email || ! is_string($request->input('password')) || strlen($request->input('password')) > 1024) {
                        throw new MfaDenied;
                    }
                    $proof = app(MfaService::class)->verifyPassword($email, $request->input('password'));
                    $request->attributes->set('auth4_password_proof', $proof);
                } catch (\Throwable) {
                    return response()->view('auth.invitation-result', ['denied' => true], 403, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
                }
            }

            return app(RequireAdminMfa::class)->handle($request, $next);
        }

        return $next($request);
    }
}
