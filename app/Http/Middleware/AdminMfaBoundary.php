<?php

namespace App\Http\Middleware;

use App\Services\Auth\MfaPolicy;
use Closure;
use Illuminate\Http\Request;

/** Глобальна межа: також default Livewire update/upload/preview та прямі запити. */
final class AdminMfaBoundary
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('mfa.enabled')) {
            return $next($request);
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

            return app(RequireAdminMfa::class)->handle($request, $next);
        }

        return $next($request);
    }
}
