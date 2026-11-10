<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SaborHelado;
use App\Models\User;

class SaborHeladoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('heladeria.sabores_ver');
    }

    public function view(User $user, SaborHelado $saborHelado): bool
    {
        return $user->can('heladeria.sabores_ver');
    }

    public function create(User $user): bool
    {
        return $user->can('heladeria.sabores_crear');
    }

    public function update(User $user, SaborHelado $saborHelado): bool
    {
        return $user->can('heladeria.sabores_editar');
    }

    public function delete(User $user, SaborHelado $saborHelado): bool
    {
        return false;
    }
}
