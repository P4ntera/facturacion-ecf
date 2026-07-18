<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Categoria;
use App\Models\User;

class CategoriaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('categorias.ver');
    }

    public function view(User $user, Categoria $categoria): bool
    {
        return $user->can('categorias.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('categorias.crear');
    }

    public function update(User $user, Categoria $categoria): bool
    {
        return $user->can('categorias.editar');
    }

    // Los maestros con historial no se borran físicamente: se desactivan (campo 'activo').
    public function delete(User $user, Categoria $categoria): bool
    {
        return false;
    }
}
