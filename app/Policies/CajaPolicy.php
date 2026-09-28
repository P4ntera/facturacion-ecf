<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Caja;
use App\Models\User;

class CajaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cajas.ver');
    }

    public function view(User $user, Caja $caja): bool
    {
        return $user->can('cajas.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('cajas.crear');
    }

    public function update(User $user, Caja $caja): bool
    {
        return $user->can('cajas.editar');
    }

    // Una caja con ventas/arqueos no se borra físicamente: se desactiva (campo 'activo').
    public function delete(User $user, Caja $caja): bool
    {
        return false;
    }
}
