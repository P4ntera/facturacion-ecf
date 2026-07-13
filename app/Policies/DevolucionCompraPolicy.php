<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DevolucionCompra;
use App\Models\User;

class DevolucionCompraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gestionar_compras');
    }

    public function view(User $user, DevolucionCompra $devolucionCompra): bool
    {
        return $user->can('gestionar_compras');
    }

    public function create(User $user): bool
    {
        return $user->can('gestionar_compras');
    }

    public function delete(User $user, DevolucionCompra $devolucionCompra): bool
    {
        return false;
    }
}
