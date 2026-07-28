<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Impresora;
use App\Models\User;

class ImpresoraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('impresoras.administrar');
    }

    public function view(User $user, Impresora $impresora): bool
    {
        return $user->can('impresoras.administrar');
    }

    public function create(User $user): bool
    {
        return $user->can('impresoras.administrar');
    }

    public function update(User $user, Impresora $impresora): bool
    {
        return $user->can('impresoras.administrar');
    }

    // Sin borrado físico: se desactiva con `activa`, coherente con SecuenciaNcf y el resto del
    // sistema (los usuarios pueden tener una impresora asignada; borrarla rompería el historial).
    public function delete(User $user, Impresora $impresora): bool
    {
        return false;
    }
}
