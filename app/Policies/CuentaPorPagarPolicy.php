<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CuentaPorPagar;
use App\Models\User;

class CuentaPorPagarPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cxp.ver');
    }

    public function view(User $user, CuentaPorPagar $cuentaPorPagar): bool
    {
        return $user->can('cxp.ver');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, CuentaPorPagar $cuentaPorPagar): bool
    {
        return false;
    }
}
