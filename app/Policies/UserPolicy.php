<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function create(User $user): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('usuarios.gestionar');
    }
}
