<?php

namespace App\Services\Auth\Budgets;

final readonly class BudgetDecision
{
    public function __construct(public bool $allowed, public int $retryAfter, public float $lockMilliseconds = 0)
    {
        if ($retryAfter < 0 || $retryAfter > 86400 || (! $allowed && $retryAfter < 1)) {
            throw new BudgetUnavailable;
        }
    }
}
