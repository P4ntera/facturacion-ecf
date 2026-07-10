<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EstadoFiscal;
use App\Jobs\EnviarEcfJob;
use App\Models\Venta;

class VentaObserver
{
    /**
     * Dispara el envío del e-CF a cola tras registrar la venta, sin bloquear el cobro. Solo si
     * la venta es electrónica (tiene e-NCF asignado) y quedó PENDIENTE de transmitir.
     */
    public function created(Venta $venta): void
    {
        if ($venta->esElectronica() && $venta->estado_fiscal === EstadoFiscal::PENDIENTE) {
            EnviarEcfJob::dispatch($venta);
        }
    }
}
