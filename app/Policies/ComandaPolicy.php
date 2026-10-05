<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoComanda;
use App\Models\Comanda;
use App\Models\User;

class ComandaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('comandas.ver');
    }

    public function view(User $user, Comanda $comanda): bool
    {
        return $user->can('comandas.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('comandas.crear');
    }

    public function update(User $user, Comanda $comanda): bool
    {
        return $user->can('comandas.editar')
            && ! in_array($comanda->estado, [EstadoComanda::CERRADA, EstadoComanda::CANCELADA]);
    }

    public function delete(User $user, Comanda $comanda): bool
    {
        return false;
    }
}
