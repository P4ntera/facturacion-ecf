<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteHeladeriaVentasDiariasPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $filas_raw = $servicio->heladeriaVentasDiariasQuery($desde, $hasta, $this->empresaId($request))
            ->orderBy('dia')
            ->get();

        $filas = $filas_raw->map(fn (object $fila) => [
            'dia' => \Carbon\Carbon::parse($fila->dia)->format('d/m/Y'),
            'transacciones' => number_format((int) $fila->transacciones),
            'unidades' => rtrim(rtrim(number_format((float) $fila->unidades, 3, '.', ''), '0'), '.'),
            'ingresos' => number_format((float) $fila->ingresos, 2),
        ])->all();

        $totales = [
            'dia' => 'Totales',
            'transacciones' => number_format((int) $this->sumarBc($filas_raw, 'transacciones')),
            'unidades' => rtrim(rtrim(number_format((float) $this->sumarBc($filas_raw, 'unidades'), 3, '.', ''), '0'), '.'),
            'ingresos' => number_format((float) $this->sumarBc($filas_raw, 'ingresos'), 2),
        ];

        return $this->responder(
            request: $request,
            titulo: 'Ventas diarias de heladería',
            columnas: [
                ['key' => 'dia', 'label' => 'Fecha'],
                ['key' => 'transacciones', 'label' => 'Transacciones', 'align' => 'text-right'],
                ['key' => 'unidades', 'label' => 'Unidades', 'align' => 'text-right'],
                ['key' => 'ingresos', 'label' => 'Ingresos', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
