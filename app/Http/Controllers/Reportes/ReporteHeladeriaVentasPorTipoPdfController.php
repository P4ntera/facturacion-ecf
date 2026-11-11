<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteHeladeriaVentasPorTipoPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $filas_raw = $servicio->heladeriaVentasPorTipoQuery($desde, $hasta, $this->empresaId($request))
            ->orderByDesc('ingresos')
            ->get();

        $filas = $filas_raw->map(fn (object $fila) => [
            'tipo' => $fila->tipo,
            'cantidad' => rtrim(rtrim(number_format((float) $fila->cantidad, 3, '.', ''), '0'), '.'),
            'ingresos' => number_format((float) $fila->ingresos, 2),
        ])->all();

        $totales = [
            'tipo' => 'Totales',
            'cantidad' => rtrim(rtrim(number_format((float) $this->sumarBc($filas_raw, 'cantidad'), 3, '.', ''), '0'), '.'),
            'ingresos' => number_format((float) $this->sumarBc($filas_raw, 'ingresos'), 2),
        ];

        return $this->responder(
            request: $request,
            titulo: 'Ventas de heladería por tipo',
            columnas: [
                ['key' => 'tipo', 'label' => 'Tipo'],
                ['key' => 'cantidad', 'label' => 'Unidades', 'align' => 'text-right'],
                ['key' => 'ingresos', 'label' => 'Ingresos', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
