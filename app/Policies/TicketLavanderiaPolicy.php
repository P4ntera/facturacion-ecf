<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TicketLavanderia;
use App\Models\User;

class TicketLavanderiaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('lavanderia.ver_tickets');
    }

    public function view(User $user, TicketLavanderia $ticket): bool
    {
        return $user->can('lavanderia.ver_tickets');
    }

    public function create(User $user): bool
    {
        return $user->can('lavanderia.crear_ticket');
    }

    public function update(User $user, TicketLavanderia $ticket): bool
    {
        return $user->can('lavanderia.cambiar_estado');
    }

    public function delete(User $user, TicketLavanderia $ticket): bool
    {
        return false;
    }
}
