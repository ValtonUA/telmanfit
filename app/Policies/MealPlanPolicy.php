<?php

namespace App\Policies;

use App\Models\User;
use App\Services\MealPlans\MealPlanFoundation;
use Illuminate\Database\Eloquent\Model;

class MealPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return MealPlanFoundation::allowed($user);
    }

    public function view(User $user, Model $record): bool
    {
        return MealPlanFoundation::allowed($user);
    }

    public function create(User $user): bool
    {
        return MealPlanFoundation::allowed($user);
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return false;
    }
}
