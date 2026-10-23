<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServicioLavanderia;
use App\Models\User;

class ServicioLavanderiaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function view(User $user, ServicioLavanderia $servicioLavanderia): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function create(User $user): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function update(User $user, ServicioLavanderia $servicioLavanderia): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function delete(User $user, ServicioLavanderia $servicioLavanderia): bool
    {
        return false;
    }
}
