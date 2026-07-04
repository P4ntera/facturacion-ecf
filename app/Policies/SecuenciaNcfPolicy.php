<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SecuenciaNcf;
use App\Models\User;

class SecuenciaNcfPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('administrar_secuencias');
    }

    public function view(User $user, SecuenciaNcf $secuenciaNcf): bool
    {
        return $user->can('administrar_secuencias');
    }

    public function create(User $user): bool
    {
        return $user->can('administrar_secuencias');
    }

    public function update(User $user, SecuenciaNcf $secuenciaNcf): bool
    {
        return $user->can('administrar_secuencias');
    }

    // Los rangos consumidos quedan como historial: no se borran físicamente, se desactivan (activa).
    public function delete(User $user, SecuenciaNcf $secuenciaNcf): bool
    {
        return false;
    }
}
