<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArqueoCaja;
use App\Models\User;

class ArqueoCajaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gestionar_arqueo_caja');
    }

    public function view(User $user, ArqueoCaja $arqueoCaja): bool
    {
        return $user->can('gestionar_arqueo_caja');
    }

    public function create(User $user): bool
    {
        return $user->can('gestionar_arqueo_caja');
    }

    public function delete(User $user, ArqueoCaja $arqueoCaja): bool
    {
        return false;
    }
}
