<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Venta;

class VentaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('registrar_ventas');
    }

    public function view(User $user, Venta $venta): bool
    {
        return $user->can('registrar_ventas');
    }

    // La creación de ventas es exclusiva del Punto de Venta (VentaService::registrar).
    public function create(User $user): bool
    {
        return false;
    }

    // Una venta ya emitida no se edita a mano; para revertirla existe anular().
    public function update(User $user, Venta $venta): bool
    {
        return false;
    }

    public function delete(User $user, Venta $venta): bool
    {
        return false;
    }
}
