<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CuentaPorCobrar;
use App\Models\User;

class CuentaPorCobrarPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cxc.ver');
    }

    public function view(User $user, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return $user->can('cxc.ver');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return false;
    }
}
