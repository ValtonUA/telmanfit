<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;

/** Лише controlled HTTP: session lock → domain locks → save → unlock. */
final class SerializedAuthSession extends StartSession
{
    protected function collectGarbage(Session $session)
    {
        // T5.1 GET не запускає retention і не змінює навіть session metadata.
        if (! request()->isMethodSafe() || ! request()->is('api/meal-plan')) {
            parent::collectGarbage($session);
        }
    }

    protected function saveSession($request)
    {
        if (! $request->isMethodSafe() || ! $request->is('api/meal-plan')) {
            parent::saveSession($request);
        }
    }

    public function handle($request, Closure $next)
    {
        $controlled = (config('auth4.enabled') && ClientAuthBoundary::covers($request))
            || (config('mfa.enabled') && $request->is('admin', 'admin/*'));
        if (! $controlled) {
            return parent::handle($request, $next);
        }
        if (config('session.driver') !== 'database' || config('database.default') !== 'pgsql') {
            return response()->json(['code' => 'auth_unavailable'], 503, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }
        $session = $this->getSession($request);
        $key = hash('sha256', $session->getId());
        $db = DB::connection(config('session.connection'));
        $locked = false;
        try {
            try {
                $timeout = $db->selectOne("SELECT current_setting('lock_timeout') AS value")->value;
                try {
                    $db->select("SELECT set_config('lock_timeout', '5s', false)");
                    $db->select('SELECT pg_advisory_lock(hashtextextended(?, 606))', [$key]);
                    $locked = true;
                } finally {
                    $db->select("SELECT set_config('lock_timeout', ?, false)", [$timeout]);
                }
            } catch (\Throwable) {
                return response()->json(['code' => 'session_busy'], 503, ['Retry-After' => '1', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
            }
            $supplied = $request->cookies->get($session->getName());
            if ($supplied === $session->getId() && ! $request->is('api/auth/csrf', 'admin/login')
                && ! $db->table(config('session.table'))->where('id', $supplied)->exists()) {
                // Ротація/logout уже знищили ID. Жодного save чи Set-Cookie від follower.
                return response()->json(['code' => 'unauthenticated'], $request->is('admin', 'admin/*') ? 403 : 401, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
            }

            return parent::handle($request, $next);
        } finally {
            if ($locked) {
                $db->select('SELECT pg_advisory_unlock(hashtextextended(?, 606))', [$key]);
            }
        }
    }

    protected function addCookieToResponse($response, $session)
    {
        $request = request();
        $controlled = in_array($session->getName(), ['telmanfit_admin_session', 'telmanfit_client_session'], true);
        if (! $controlled) {
            return parent::addCookieToResponse($response, $session);
        }
        if ($request->cookies->get($session->getName()) === $session->getId()) {
            // Пізні відповіді зі старого ID не можуть перезаписати cookie ротації.
            if (! $request->is('api/auth/csrf')) {
                foreach ($response->headers->getCookies() as $cookie) {
                    if ($cookie->getName() === 'XSRF-TOKEN') {
                        $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
                    }
                }
            }

            return;
        }
        parent::addCookieToResponse($response, $session);
        // Cookie — envelope до absolute TTL. Idle TTL продовжує перевіряти БД;
        // його не можна прив'язувати до рідкісного Set-Cookie після rotation.
        if (config('session.expire_on_close')) {
            return;
        }
        $admin = $session->getName() === 'telmanfit_admin_session';
        $expiry = DB::table('auth_session_states')->where('session_id', $session->getId())->value('absolute_expires_at');
        $expires = $expiry ? strtotime($expiry) : time() + ($admin ? 28800 : 604800);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $session->getName()) {
                $response->headers->setCookie($cookie->withExpires($expires));
            }
        }
    }
}
