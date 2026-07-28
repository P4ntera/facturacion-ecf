<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentoRecibido;
use App\Models\User;

class DocumentoRecibidoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ecf.gestionar');
    }

    public function view(User $user, DocumentoRecibido $documentoRecibido): bool
    {
        return $user->can('ecf.gestionar');
    }

    public function create(User $user): bool
    {
        return false;
    }

    // Solo se permite actualizar la decisión de aprobación comercial (ver Resource); nunca a mano.
    public function update(User $user, DocumentoRecibido $documentoRecibido): bool
    {
        return false;
    }

    // Es el registro de auditoría de lo que llegó a nuestros endpoints públicos: no se borra.
    public function delete(User $user, DocumentoRecibido $documentoRecibido): bool
    {
        return false;
    }
}
