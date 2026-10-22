<?php

namespace Tests\Http;

final class Auth6Barrier
{
    public function handle($request, $next)
    {
        $id = $request->header('X-Auth6-Barrier');
        $phase = $request->header('X-Auth6-Phase', 'after');
        if ($id && $phase === 'before') {
            $this->wait($id);
        }
        if ($probe = $request->header('X-Auth6-Probe')) {
            $request->session()->put('auth6_probe.'.$probe, true);
        }
        $response = $next($request);
        if ($id && $phase === 'after') {
            $this->wait($id);
        }

        return $response;
    }

    private function wait(string $id): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/D', $id)) {
            throw new \RuntimeException('Invalid synthetic barrier');
        }
        $path = getenv('AUTH6_WORK').'/'.$id;
        touch($path.'.arrived');
        $deadline = microtime(true) + 10;
        while (! is_file($path.'.release')) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Synthetic barrier timeout');
            }
            usleep(10000);
        }
    }
}
