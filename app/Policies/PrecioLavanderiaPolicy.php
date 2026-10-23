<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrecioLavanderia;
use App\Models\User;

class PrecioLavanderiaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function view(User $user, PrecioLavanderia $precio): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function create(User $user): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function update(User $user, PrecioLavanderia $precio): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function delete(User $user, PrecioLavanderia $precio): bool
    {
        return false;
    }
}
