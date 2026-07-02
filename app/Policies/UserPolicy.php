<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gestionar_usuarios');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('gestionar_usuarios');
    }

    public function create(User $user): bool
    {
        return $user->can('gestionar_usuarios');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('gestionar_usuarios');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('gestionar_usuarios');
    }
}
