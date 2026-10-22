<?php

namespace App\Services\Auth\Budgets;

/** Лише явний новий isolated cluster; немає production/local live cutover flag. */
final class SyntheticBudgets
{
    public static function enabled(): bool
    {
        if (getenv('TELMANFIT_B6_TEST') !== '1') {
            return false;
        }
        if (! app()->environment('testing') || getenv('TELMANFIT_PG_TEST') !== '1') {
            throw new BudgetUnavailable;
        }
        require base_path('tests/pg/auth2-guard.php');

        return true;
    }
}
