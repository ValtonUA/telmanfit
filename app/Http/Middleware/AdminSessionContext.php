<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Перед StartSession. Інші guard/context не можуть підставити admin cookie. */
final class AdminSessionContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        $original = config('session');
        if (config('mfa.enabled') && $request->is('admin', 'admin/*')) {
            config(['session.cookie' => 'telmanfit_admin_session', 'session.path' => '/admin',
                'session.lifetime' => 15, 'session.encrypt' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.domain' => null]);
        }

        try {
            $response = $next($request);
            if ($request->is('admin', 'admin/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        } finally {
            config(['session' => $original]);
        }
    }
}
