<?php

namespace App\Services\Auth\Client;

use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Support\Facades\Cache;

/** Виклик лише під ControlledAuth PG lock; database cache — та сама транзакція. */
final class AuthBudget
{
    public function __construct(private ControlledAuth $auth) {}

    public function take(string $key, int $limit, int $seconds): bool
    {
        app(RestoreFence::class)->assert(issuance: true);
        $key = 'auth4:'.$this->auth->fingerprint($key);
        $now = (float) $this->auth->now()->format('U.u');
        $events = array_values(array_filter(Cache::store('database')->get($key, []), fn ($t) => $t > $now - $seconds));
        if (count($events) >= $limit) {
            return false;
        }
        $events[] = $now;
        if (! Cache::store('database')->put($key, $events, $seconds + 1)) {
            throw new AuthDenied('auth_unavailable', 503);
        }

        return true;
    }

    public function allows(string $key, int $limit, int $seconds): bool
    {
        $events = Cache::store('database')->get('auth4:'.$this->auth->fingerprint($key), []);
        $now = (float) $this->auth->now()->format('U.u');

        return count(array_filter($events, fn ($t) => $t > $now - $seconds)) < $limit;
    }

    public function blocked(string $email): bool
    {
        return Cache::store('database')->has('auth4:pause:'.$this->auth->fingerprint($email));
    }

    public function failure(string $email): void
    {
        // П'ятий failure починає повну 15-хвилинну паузу.
        if (! $this->take('failure:'.$email, 4, 900)) {
            if (! Cache::store('database')->put('auth4:pause:'.$this->auth->fingerprint($email), true, 901)) {
                throw new AuthDenied('auth_unavailable', 503);
            }
        }
    }
}
