<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.gestionar');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.gestionar');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.gestionar');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.gestionar');
    }

    // El borrado con sus protecciones (Administrador, roles con usuarios) lo maneja una acción
    // propia en RoleResource con Notification explicando el motivo; aquí se desactiva el flujo
    // de borrado nativo de Filament para no tener dos caminos distintos hacia lo mismo.
    public function delete(User $user, Role $role): bool
    {
        return false;
    }
}
