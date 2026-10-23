<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TipoPrenda;
use App\Models\User;

class TipoPrendaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function view(User $user, TipoPrenda $tipoPrenda): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function create(User $user): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function update(User $user, TipoPrenda $tipoPrenda): bool
    {
        return $user->can('lavanderia.configurar');
    }

    public function delete(User $user, TipoPrenda $tipoPrenda): bool
    {
        return false;
    }
}
