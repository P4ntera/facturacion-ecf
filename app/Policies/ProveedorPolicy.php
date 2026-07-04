<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Proveedor;
use App\Models\User;

class ProveedorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function view(User $user, Proveedor $proveedor): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function create(User $user): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function update(User $user, Proveedor $proveedor): bool
    {
        return $user->can('gestionar_maestros');
    }

    // Los maestros con historial no se borran físicamente: se desactivan (campo 'activo').
    public function delete(User $user, Proveedor $proveedor): bool
    {
        return false;
    }
}
