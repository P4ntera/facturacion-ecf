<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SecuenciaNcf;
use App\Models\User;

class SecuenciaNcfPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('secuencias.administrar');
    }

    public function view(User $user, SecuenciaNcf $secuenciaNcf): bool
    {
        return $user->can('secuencias.administrar');
    }

    public function create(User $user): bool
    {
        return $user->can('secuencias.administrar');
    }

    public function update(User $user, SecuenciaNcf $secuenciaNcf): bool
    {
        return $user->can('secuencias.administrar');
    }

    // Los rangos consumidos quedan como historial: no se borran físicamente, se desactivan (activa).
    public function delete(User $user, SecuenciaNcf $secuenciaNcf): bool
    {
        return false;
    }
}
