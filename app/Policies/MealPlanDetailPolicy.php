<?php

namespace App\Policies;

use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealPlanStructure;

class MealPlanDetailPolicy extends MealPlanPolicy
{
    public function editStructure(User $user, MealPlanVersion $record): bool
    {
        return MealPlanStructure::allowed($user, $record);
    }

    public function create(User $user): bool
    {
        return false;
    }
}
