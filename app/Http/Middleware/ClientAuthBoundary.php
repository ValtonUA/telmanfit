<?php

namespace App\Http\Middleware;

use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\MfaDenied;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Охоплює session/CSRF/controller faults; жодних SQL bindings у logger/JSON. */
final class ClientAuthBoundary
{
    public static function covers(Request $request): bool
    {
        return $request->is('api/auth/*', 'api/me')
            || (app()->environment(['local', 'testing']) && $request->is('api/onboarding'));
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! self::covers($request)) {
            return $next($request);
        }
        if (! config('auth4.enabled')) {
            return response()->json(['code' => 'not_found'], 404);
        }
        if ($request->is('api/auth/security/*') && ! in_array(config('auth5.enabled'), [true, 'true', '1'], true)) {
            return response()->json(['code' => 'not_found'], 404);
        }
        $original = config('session');
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');
            app(ControlledAuth::class)->assert();
            config(['session.cookie' => 'telmanfit_client_session', 'session.path' => '/api', 'session.domain' => null,
                'session.http_only' => true, 'session.same_site' => 'lax', 'session.lifetime' => 720, 'session.encrypt' => true]);
            $response = $next($request);
        } catch (AuthDenied $e) {
            $response = response()->json(['code' => $e->reason, 'message' => 'Не вдалося завершити запит.'], $e->status);
            if ($e->status === 429) {
                $response->headers->set('Retry-After', '900');
            }
        } catch (ValidationException $e) {
            $response = response()->json(['code' => 'validation_error', 'message' => 'Перевірте дані.', 'errors' => $e->errors()], 422);
        } catch (HttpExceptionInterface $e) {
            $response = response()->json(['code' => $e->getStatusCode() === 419 ? 'csrf_mismatch' : 'request_denied'], $e->getStatusCode());
        } catch (MfaDenied) {
            $response = response()->json(['code' => 'invalid_challenge'], 422);
        } catch (\Throwable) {
            $response = response()->json(['code' => 'auth_unavailable'], 503);
        } finally {
            config(['session' => $original]);
            app()->setLocale($originalLocale);
        }
        // Readable CSRF cookie доступна SPA /app, session cookie залишається HttpOnly /api.
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $response->headers->removeCookie('XSRF-TOKEN', $cookie->getPath(), $cookie->getDomain());
                $response->headers->setCookie(new Cookie('TELMANFIT-CLIENT-XSRF', $cookie->getValue(),
                    $cookie->getExpiresTime(), '/', null, $cookie->isSecure(), false, false, 'lax'));
            }
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
