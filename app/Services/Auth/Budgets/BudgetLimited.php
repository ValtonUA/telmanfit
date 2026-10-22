<?php

namespace App\Services\Auth\Budgets;

final class BudgetLimited extends \RuntimeException
{
    public function __construct(public readonly BudgetDecision $decision)
    {
        parent::__construct('rate_limited');
    }
}
