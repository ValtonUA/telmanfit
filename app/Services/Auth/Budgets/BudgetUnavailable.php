<?php

namespace App\Services\Auth\Budgets;

/** Без previous exception: SQL bindings, IP та email не потрапляють до trace. */
final class BudgetUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('rate_budget_unavailable');
    }
}
