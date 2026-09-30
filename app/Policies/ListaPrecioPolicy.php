<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ListaPrecio;
use App\Models\User;

class ListaPrecioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('listas_precio.ver');
    }

    public function view(User $user, ListaPrecio $listaPrecio): bool
    {
        return $user->can('listas_precio.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('listas_precio.crear');
    }

    public function update(User $user, ListaPrecio $listaPrecio): bool
    {
        return $user->can('listas_precio.editar');
    }

    public function delete(User $user, ListaPrecio $listaPrecio): bool
    {
        return false;
    }
}
