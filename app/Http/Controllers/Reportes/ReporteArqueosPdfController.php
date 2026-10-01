<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\ArqueoCaja;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteArqueosPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $arqueos = $servicio->arqueosEnRangoQuery($desde, $hasta, $this->empresaId($request))
            ->orderBy('abierto_en')
            ->get();

        $filas = $arqueos->map(fn (ArqueoCaja $arqueo) => [
            'apertura' => $arqueo->abierto_en->format('d/m/Y H:i'),
            'cierre' => $arqueo->cerrado_en?->format('d/m/Y H:i') ?? '—',
            'cajero' => $arqueo->user?->name ?? '—',
            'caja' => $arqueo->caja?->nombre ?? '—',
            'fondo' => number_format((float) $arqueo->fondo_inicial, 2),
            'efectivo' => number_format((float) $arqueo->total_ventas_efectivo, 2),
            'tarjeta' => number_format((float) $arqueo->total_ventas_tarjeta, 2),
            'transferencia' => number_format((float) $arqueo->total_ventas_transferencia, 2),
            'esperado' => number_format((float) $arqueo->efectivo_esperado, 2),
            'contado' => number_format((float) $arqueo->efectivo_contado, 2),
            'diferencia' => number_format((float) $arqueo->diferencia, 2),
            'estado' => $arqueo->estado->etiqueta(),
        ])->all();

        return $this->responder(
            request: $request,
            titulo: 'Reporte de arqueos de caja',
            columnas: [
                ['key' => 'apertura', 'label' => 'Apertura'],
                ['key' => 'cierre', 'label' => 'Cierre'],
                ['key' => 'cajero', 'label' => 'Cajero'],
                ['key' => 'caja', 'label' => 'Caja'],
                ['key' => 'fondo', 'label' => 'Fondo', 'align' => 'text-right'],
                ['key' => 'efectivo', 'label' => 'Efectivo', 'align' => 'text-right'],
                ['key' => 'tarjeta', 'label' => 'Tarjeta', 'align' => 'text-right'],
                ['key' => 'transferencia', 'label' => 'Transfer.', 'align' => 'text-right'],
                ['key' => 'esperado', 'label' => 'Esperado', 'align' => 'text-right'],
                ['key' => 'contado', 'label' => 'Contado', 'align' => 'text-right'],
                ['key' => 'diferencia', 'label' => 'Diferencia', 'align' => 'text-right'],
                ['key' => 'estado', 'label' => 'Estado'],
            ],
            filas: $filas,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
