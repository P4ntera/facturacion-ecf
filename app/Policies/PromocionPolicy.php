<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Promocion;
use App\Models\User;

class PromocionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('promociones.ver');
    }

    public function view(User $user, Promocion $promocion): bool
    {
        return $user->can('promociones.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('promociones.crear');
    }

    public function update(User $user, Promocion $promocion): bool
    {
        return $user->can('promociones.editar');
    }

    // Los maestros con historial no se borran físicamente: se desactivan (campo 'activa').
    public function delete(User $user, Promocion $promocion): bool
    {
        return false;
    }
}
