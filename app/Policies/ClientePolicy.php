<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cliente;
use App\Models\User;

class ClientePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function view(User $user, Cliente $cliente): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function create(User $user): bool
    {
        return $user->can('gestionar_maestros');
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $user->can('gestionar_maestros');
    }

    // Los maestros con historial no se borran físicamente: se desactivan (campo 'activo').
    public function delete(User $user, Cliente $cliente): bool
    {
        return false;
    }
}
