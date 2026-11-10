<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ComboHelado;
use App\Models\User;

class ComboHeladoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('heladeria.combos_ver');
    }

    public function view(User $user, ComboHelado $comboHelado): bool
    {
        return $user->can('heladeria.combos_ver');
    }

    public function create(User $user): bool
    {
        return $user->can('heladeria.combos_crear');
    }

    public function update(User $user, ComboHelado $comboHelado): bool
    {
        return $user->can('heladeria.combos_editar');
    }

    public function delete(User $user, ComboHelado $comboHelado): bool
    {
        return false;
    }
}
