<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PedidoCompra;
use App\Models\User;

class PedidoCompraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('compras.ver');
    }

    public function view(User $user, PedidoCompra $pedidoCompra): bool
    {
        return $user->can('compras.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('compras.crear');
    }

    public function delete(User $user, PedidoCompra $pedidoCompra): bool
    {
        return false;
    }
}
