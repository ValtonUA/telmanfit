<?php

namespace App\Services\Auth\Budgets;

final readonly class BudgetKey
{
    public string $hash;

    public function __construct(public BudgetScope $scope, #[\SensitiveParameter] string $input)
    {
        $secret = config('auth4.rate_key');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new BudgetUnavailable;
        }
        // Scope framing не допускає перетин ключів різних policies. Rotation — окремий cutover.
        $this->hash = hash_hmac('sha256', "TelmanFit/rate/v1\0".$scope->value."\0".$input, $secret);
    }
}
