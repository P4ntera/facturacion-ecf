<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PresentacionHelado;
use App\Models\User;

class PresentacionHeladoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('heladeria.presentaciones_ver');
    }

    public function view(User $user, PresentacionHelado $presentacionHelado): bool
    {
        return $user->can('heladeria.presentaciones_ver');
    }

    public function create(User $user): bool
    {
        return $user->can('heladeria.presentaciones_crear');
    }

    public function update(User $user, PresentacionHelado $presentacionHelado): bool
    {
        return $user->can('heladeria.presentaciones_editar');
    }

    public function delete(User $user, PresentacionHelado $presentacionHelado): bool
    {
        return false;
    }
}
