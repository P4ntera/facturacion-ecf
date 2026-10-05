<?php

namespace App\Console\Commands;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Jobs\EnviarEcfJob;
use App\Models\Venta;
use App\Services\Dgii\EnvioEcfService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Red de seguridad del envío de e-CF. Lo corre el programador cada 5 minutos (routes/console.php):
 *
 * 1. PENDIENTES: e-CF que nunca llegaron al PAC (se agotaron los reintentos de EnviarEcfJob, el
 *    worker estuvo caído, etc.). Se vuelven a encolar si su último intento fue hace más de
 *    10 minutos, para no pisar a un job que todavía está en su backoff.
 * 2. EN PROCESO: el PAC los recibió pero la DGII aún no respondía. Se consulta el estado (lo mismo
 *    que el botón "Refrescar estado"), para que pasen solos a aceptado/rechazado.
 *
 * Recorre todas las empresas: cada venta resuelve su propio gateway desde $venta->empresa.
 */
class ProcesarEcfPendientes extends Command
{
    protected $signature = 'ecf:procesar-pendientes {--limite=200 : Máximo de ventas por grupo en cada corrida}';

    protected $description = 'Reencola los e-CF pendientes y consulta el estado de los que están en proceso';

    public function handle(EnvioEcfService $envio): int
    {
        $limite = (int) $this->option('limite');

        $reencolados = $this->reencolarPendientes($limite);
        [$refrescados, $fallidos] = $this->refrescarEnProceso($envio, $limite);

        $this->info("e-CF reencolados: {$reencolados}. Estados consultados: {$refrescados}. Consultas fallidas: {$fallidos}.");

        return self::SUCCESS;
    }

    private function reencolarPendientes(int $limite): int
    {
        $ventas = Venta::query()
            ->where('estado_fiscal', EstadoFiscal::PENDIENTE)
            ->where('estado', '!=', EstadoVenta::ANULADA)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->orderBy('id')
            ->limit($limite)
            ->get()
            ->filter(fn (Venta $venta) => EnviarEcfJob::puedeEnviarse($venta));

        foreach ($ventas as $venta) {
            // Si ya hay un envío de esta venta en cola, ShouldBeUnique descarta este.
            EnviarEcfJob::dispatch($venta);
        }

        return $ventas->count();
    }

    /** @return array{0: int, 1: int} */
    private function refrescarEnProceso(EnvioEcfService $envio, int $limite): array
    {
        $ventas = Venta::query()
            ->where('estado_fiscal', EstadoFiscal::EN_PROCESO)
            ->whereNotNull('pac_id')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->orderBy('id')
            ->limit($limite)
            ->get();

        $refrescados = 0;
        $fallidos = 0;

        foreach ($ventas as $venta) {
            try {
                $envio->refrescarEstado($venta);
                $refrescados++;
            } catch (Throwable $e) {
                // Una venta que falla no detiene a las demás; queda para la próxima corrida.
                report($e);
                $fallidos++;
            }
        }

        return [$refrescados, $fallidos];
    }
}
