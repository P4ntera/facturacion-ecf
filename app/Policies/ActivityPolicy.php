<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ver_auditoria');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('ver_auditoria');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    // El log de auditoría es append-only: nunca se borra, igual que el Kardex.
    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
