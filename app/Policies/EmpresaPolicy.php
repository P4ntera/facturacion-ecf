<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Empresa;
use App\Models\User;

/**
 * Empresa es el propio modelo de tenant: administrarlas (altas, datos fiscales básicos,
 * activar/desactivar) es una operación fuera del alcance de cualquier empresa individual, así
 * que se gatea por es_super_admin y no por un permiso granular de Permisos::catalogo().
 */
class EmpresaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->es_super_admin;
    }

    public function view(User $user, Empresa $empresa): bool
    {
        return $user->es_super_admin;
    }

    public function create(User $user): bool
    {
        return $user->es_super_admin;
    }

    public function update(User $user, Empresa $empresa): bool
    {
        return $user->es_super_admin;
    }

    // Sin borrado físico: una empresa se retira del servicio desactivándola (campo 'activa').
    public function delete(User $user, Empresa $empresa): bool
    {
        return false;
    }
}
