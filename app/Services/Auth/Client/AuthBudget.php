<?php

namespace App\Services\Auth\Client;

use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\LegacyBudgetMap;
use App\Services\Auth\Budgets\PostgresBudgets;
use App\Services\Auth\Budgets\SyntheticBudgets;
use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Support\Facades\Cache;

/** Legacy під 4040404; B6 synthetic admission комітиться окремим PG connection. */
final class AuthBudget
{
    public function __construct(private ControlledAuth $auth) {}

    public function take(string $key, int $limit, int $seconds): bool
    {
        if (SyntheticBudgets::enabled()) {
            $typed = LegacyBudgetMap::key($key, $limit, $seconds);

            return str_starts_with($key, 'recovery.totp:')
                ? app(PostgresBudgets::class)->failures([$typed], true)->allowed
                : app(PostgresBudgets::class)->admit([$typed])->allowed;
        }
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
        if (SyntheticBudgets::enabled()) {
            return app(PostgresBudgets::class)->failures([LegacyBudgetMap::key($key, $limit, $seconds)])->allowed;
        }
        $events = Cache::store('database')->get('auth4:'.$this->auth->fingerprint($key), []);
        $now = (float) $this->auth->now()->format('U.u');

        return count(array_filter($events, fn ($t) => $t > $now - $seconds)) < $limit;
    }

    public function blocked(string $email): bool
    {
        if (SyntheticBudgets::enabled()) {
            return ! app(PostgresBudgets::class)->failures([new BudgetKey(BudgetScope::OtpFailure, $email)])->allowed;
        }

        return Cache::store('database')->has('auth4:pause:'.$this->auth->fingerprint($email));
    }

    public function failure(string $email): void
    {
        if (SyntheticBudgets::enabled()) {
            app(PostgresBudgets::class)->failures([new BudgetKey(BudgetScope::OtpFailure, $email)], true);

            return;
        }
        // П'ятий failure починає повну 15-хвилинну паузу.
        if (! $this->take('failure:'.$email, 4, 900)) {
            if (! Cache::store('database')->put('auth4:pause:'.$this->auth->fingerprint($email), true, 901)) {
                throw new AuthDenied('auth_unavailable', 503);
            }
        }
    }

    /** Email group має один check/append; suppression не витрачає інші email quotas. */
    public function emailGroup(array $limits, string $email): bool
    {
        if (SyntheticBudgets::enabled()) {
            if (! app(PostgresBudgets::class)->inspectFailures([new BudgetKey(BudgetScope::OtpFailure, $email)])->allowed) {
                return false;
            }

            return app(PostgresBudgets::class)->admit(array_map(fn ($row) => LegacyBudgetMap::key(...$row), $limits))->allowed;
        }
        $allowed = ! $this->blocked($email);
        foreach ($limits as [$key, $limit, $seconds]) {
            $allowed = $this->allows($key, $limit, $seconds) && $allowed;
        }
        if ($allowed) {
            foreach ($limits as [$key, $limit, $seconds]) {
                $this->take($key, $limit, $seconds);
            }
        }

        return $allowed;
    }
}
