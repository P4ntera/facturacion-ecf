<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AreaRestaurante;
use App\Models\User;

class AreaRestaurantePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('mesas.ver');
    }

    public function view(User $user, AreaRestaurante $area): bool
    {
        return $user->can('mesas.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('mesas.crear');
    }

    public function update(User $user, AreaRestaurante $area): bool
    {
        return $user->can('mesas.editar');
    }

    public function delete(User $user, AreaRestaurante $area): bool
    {
        return false;
    }
}
