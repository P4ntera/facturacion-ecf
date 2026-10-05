<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mesa;
use App\Models\User;

class MesaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('mesas.ver');
    }

    public function view(User $user, Mesa $mesa): bool
    {
        return $user->can('mesas.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('mesas.crear');
    }

    public function update(User $user, Mesa $mesa): bool
    {
        return $user->can('mesas.editar');
    }

    public function delete(User $user, Mesa $mesa): bool
    {
        return false;
    }
}
