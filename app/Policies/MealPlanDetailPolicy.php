<?php

namespace App\Policies;

use App\Models\User;

class MealPlanDetailPolicy extends MealPlanPolicy
{
    public function create(User $user): bool
    {
        return false;
    }
}
