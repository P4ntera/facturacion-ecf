<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrdenCompra;
use App\Models\User;

class OrdenCompraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ordenes_compra.ver');
    }

    public function view(User $user, OrdenCompra $ordenCompra): bool
    {
        return $user->can('ordenes_compra.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('ordenes_compra.crear');
    }

    public function update(User $user, OrdenCompra $ordenCompra): bool
    {
        return $user->can('ordenes_compra.editar') && $ordenCompra->estado->puedeEditar();
    }

    public function delete(User $user, OrdenCompra $ordenCompra): bool
    {
        return false;
    }
}
