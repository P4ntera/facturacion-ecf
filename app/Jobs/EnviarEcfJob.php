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

    public function handle(EnvioEcfService $servicio): void
    {
        $venta = $this->venta->fresh();

        if ($venta === null || $venta->estado_fiscal === EstadoFiscal::ACEPTADO) {
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
