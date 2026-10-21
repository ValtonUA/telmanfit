<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN;
    }

    public function view(User $user, Client $client): bool
    {
        return $user->role === User::ROLE_ADMIN;
    }

    public function create(User $user): bool
    {
        // Нові клієнти — лише через персональне запрошення тренера (ще не реалізовано).
        return false;
    }

    public function update(User $user, Client $client): bool
    {
        return $user->role === User::ROLE_ADMIN;
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
