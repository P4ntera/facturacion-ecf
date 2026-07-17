<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteVentasPorClientePdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $clientes = $servicio->ventasPorCliente($desde, $hasta);

        $filas = $clientes->map(fn (object $fila) => [
            'cliente' => $fila->cliente_nombre,
            'cantidad_ventas' => (string) $fila->cantidad_ventas,
            'total_vendido' => number_format((float) $fila->total_vendido, 2),
        ])->all();

        $totales = [
            'cliente' => 'Totales',
            'cantidad_ventas' => (string) $clientes->sum('cantidad_ventas'),
            'total_vendido' => number_format((float) $this->sumarBc($clientes, 'total_vendido'), 2),
        ];

        return $this->responder(
            titulo: 'Ventas por cliente',
            columnas: [
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'cantidad_ventas', 'label' => 'Cantidad de ventas', 'align' => 'text-right'],
                ['key' => 'total_vendido', 'label' => 'Total vendido', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
