<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteVentasPorVendedorPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $vendedores = $servicio->ventasPorUsuario($desde, $hasta);

        $filas = $vendedores->map(fn (object $fila) => [
            'vendedor' => $fila->user_nombre,
            'cantidad_ventas' => (string) $fila->cantidad_ventas,
            'total_vendido' => number_format((float) $fila->total_vendido, 2),
        ])->all();

        $totales = [
            'vendedor' => 'Totales',
            'cantidad_ventas' => (string) $vendedores->sum('cantidad_ventas'),
            'total_vendido' => number_format((float) $this->sumarBc($vendedores, 'total_vendido'), 2),
        ];

        return $this->responder(
            titulo: 'Ventas por vendedor',
            columnas: [
                ['key' => 'vendedor', 'label' => 'Vendedor'],
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
