<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoNotificacion;
use App\Models\Empresa;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Único punto que decide quién recibe una notificación in-app: usuarios de ESA empresa cuyo rol
 * tiene el permiso del tipo (notificaciones.*) y que no la silenciaron en "Mis notificaciones".
 *
 * Los roles son por empresa (teams de spatie): el contexto de permisos se fija explícitamente en
 * $empresa, porque esto se llama desde observers, services y colas donde el tenant ambiente puede
 * ser otro o ninguno. El super-admin no la recibe: no pertenece a ninguna empresa y le llegarían
 * las alertas de todas mezcladas (ve las de la empresa en la que esté parado en el dashboard).
 */
class NotificacionService
{
    /** @return Collection<int, User> */
    public function destinatarios(TipoNotificacion $tipo, Empresa $empresa): Collection
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId($empresa->id);

        try {
            return User::permission($tipo->permiso())
                ->where('empresa_id', $empresa->id)
                ->whereDoesntHave('preferenciasNotificacion', fn ($query) => $query
                    ->where('tipo', $tipo->value)
                    ->where('activa', false))
                ->get();
        } finally {
            setPermissionsTeamId($anterior);
        }
    }

    /**
     * Envía $notificacion a la campana de cada destinatario. Nunca lanza: una alerta que falla
     * no debe romper la operación que la disparó (una venta, un cambio de stock, un envío e-CF).
     */
    public function enviar(TipoNotificacion $tipo, ?Empresa $empresa, Notification $notificacion): void
    {
        // Sin empresa (datos viejos sin empresa_id) no hay a quién avisar sin mezclar tenants.
        if ($empresa === null) {
            return;
        }

        try {
            $destinatarios = $this->destinatarios($tipo, $empresa);

            if ($destinatarios->isNotEmpty()) {
                $notificacion->sendToDatabase($destinatarios);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
