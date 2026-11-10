<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ToppingHelado;
use App\Models\User;

class ToppingHeladoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('heladeria.toppings_ver');
    }

    public function view(User $user, ToppingHelado $toppingHelado): bool
    {
        return $user->can('heladeria.toppings_ver');
    }

    public function create(User $user): bool
    {
        return $user->can('heladeria.toppings_crear');
    }

    public function update(User $user, ToppingHelado $toppingHelado): bool
    {
        return $user->can('heladeria.toppings_editar');
    }

    public function delete(User $user, ToppingHelado $toppingHelado): bool
    {
        return false;
    }
}
