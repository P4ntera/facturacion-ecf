<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cotizacion;
use App\Models\User;

class CotizacionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cotizaciones.ver');
    }

    public function view(User $user, Cotizacion $cotizacion): bool
    {
        return $user->can('cotizaciones.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('cotizaciones.crear');
    }

    public function update(User $user, Cotizacion $cotizacion): bool
    {
        return $user->can('cotizaciones.editar') && $cotizacion->estado->puedeEditar();
    }

    public function delete(User $user, Cotizacion $cotizacion): bool
    {
        return false;
    }
}
