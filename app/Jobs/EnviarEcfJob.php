<?php

namespace App\Jobs;

use App\Enums\EstadoFiscal;
use App\Exceptions\DgiiGatewayException;
use App\Models\Venta;
use App\Services\Dgii\EnvioEcfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envía un e-CF ya registrado al PAC sin bloquear el cobro. Correr el worker con:
 *   ./vendor/bin/sail artisan queue:work --queue=ecf
 */
class EnviarEcfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly Venta $venta)
    {
        $this->onQueue('ecf');
    }

    /** Backoff exponencial: 30s, 1min, 2min, 4min entre reintentos. */
    public function backoff(): array
    {
        return [30, 60, 120, 240];
    }

    /**
     * Único criterio de "¿se puede (re)enviar este e-CF a la DGII?" — lo usan el job y la acción
     * "Reintentar envío". Un e-NCF que la DGII ya tiene (aceptado en cualquier variante) o que
     * está procesando (EN_PROCESO: se consulta con "Refrescar estado", no se reenvía) NO se
     * vuelve a mandar: sería un e-NCF duplicado. Solo PENDIENTE (nunca llegó / error de red) y
     * RECHAZADO (corregido y reenviado). Nunca una venta anulada: su e-CF no debe salir.
     */
    public static function puedeEnviarse(Venta $venta): bool
    {
        return $venta->esElectronica()
            && ! $venta->estaAnulada()
            && in_array($venta->estado_fiscal, [EstadoFiscal::PENDIENTE, EstadoFiscal::RECHAZADO], true);
    }

    public function handle(EnvioEcfService $servicio): void
    {
        $venta = $this->venta->fresh();

        if ($venta === null || ! self::puedeEnviarse($venta)) {
            return;
        }

        $respuesta = $servicio->enviar($venta);

        // Si el problema fue de datos (p. ej. falta el RNC), EnvioEcfService ya dejó la venta en
        // RECHAZADO: es definitivo, reintentar no lo arregla. Solo se relanza (para que Laravel
        // reintente con backoff) cuando sigue PENDIENTE, es decir, cuando fue un error de red/PAC.
        if (! $respuesta->exito && $venta->refresh()->estado_fiscal === EstadoFiscal::PENDIENTE) {
            throw new DgiiGatewayException($respuesta->errorMessage ?? 'Fallo al enviar el e-CF al PAC.');
        }
    }
}
