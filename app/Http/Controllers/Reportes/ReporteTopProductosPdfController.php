<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteTopProductosPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $productos = $servicio->productosVendidosQuery($desde, $hasta, $this->empresaId($request))
            ->orderByDesc('ingresos')
            ->get();

        $filas = $productos->map(fn (object $producto) => [
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'cantidad_vendida' => rtrim(rtrim(number_format((float) $producto->cantidad_vendida, 3, '.', ''), '0'), '.'),
            'ingresos' => number_format((float) $producto->ingresos, 2),
        ])->all();

        $totales = [
            'nombre' => 'Totales',
            'cantidad_vendida' => rtrim(rtrim(number_format((float) $this->sumarBc($productos, 'cantidad_vendida'), 3, '.', ''), '0'), '.'),
            'ingresos' => number_format((float) $this->sumarBc($productos, 'ingresos'), 2),
        ];

        return $this->responder(
            titulo: 'Top de productos',
            columnas: [
                ['key' => 'codigo', 'label' => 'Código'],
                ['key' => 'nombre', 'label' => 'Producto'],
                ['key' => 'cantidad_vendida', 'label' => 'Cantidad vendida', 'align' => 'text-right'],
                ['key' => 'ingresos', 'label' => 'Ingresos', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
