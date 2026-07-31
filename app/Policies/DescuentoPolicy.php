<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Descuento;
use App\Models\User;

class DescuentoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('descuentos.ver');
    }

    public function view(User $user, Descuento $descuento): bool
    {
        return $user->can('descuentos.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('descuentos.crear');
    }

    public function update(User $user, Descuento $descuento): bool
    {
        return $user->can('descuentos.editar');
    }

    // Los maestros con historial no se borran físicamente: se desactivan (campo 'activo').
    public function delete(User $user, Descuento $descuento): bool
    {
        return false;
    }
}
