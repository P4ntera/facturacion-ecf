<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Producto;
use App\Models\User;

class ProductoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function view(User $user, Producto $producto): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function create(User $user): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function update(User $user, Producto $producto): bool
    {
        return $user->can('gestionar_maestros');
    }

    // Los maestros con historial no se borran físicamente: se desactivan (campo 'activo').
    public function delete(User $user, Producto $producto): bool
    {
        return false;
    }
}
