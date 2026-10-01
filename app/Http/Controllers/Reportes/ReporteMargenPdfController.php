<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteMargenPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $productos = $servicio->margenPorProductoQuery($desde, $hasta, $this->empresaId($request))
            ->orderByDesc('margen_porcentaje')
            ->get();

        $filas = $productos->map(fn ($p) => [
            'codigo' => $p->codigo,
            'nombre' => $p->nombre,
            'unidades' => number_format((float) $p->unidades_vendidas, 2),
            'costo' => number_format((float) $p->costo, 2),
            'ingresos' => number_format((float) $p->ingresos, 2),
            'costo_total' => number_format((float) $p->costo_total, 2),
            'ganancia' => number_format((float) $p->ganancia, 2),
            'margen' => number_format((float) $p->margen_porcentaje, 2).'%',
        ])->all();

        return $this->responder(
            request: $request,
            titulo: 'Margen por producto',
            columnas: [
                ['key' => 'codigo', 'label' => 'Código'],
                ['key' => 'nombre', 'label' => 'Producto'],
                ['key' => 'unidades', 'label' => 'Unidades', 'align' => 'text-right'],
                ['key' => 'costo', 'label' => 'Costo Unit.', 'align' => 'text-right'],
                ['key' => 'ingresos', 'label' => 'Ingresos', 'align' => 'text-right'],
                ['key' => 'costo_total', 'label' => 'Costo Total', 'align' => 'text-right'],
                ['key' => 'ganancia', 'label' => 'Ganancia', 'align' => 'text-right'],
                ['key' => 'margen', 'label' => 'Margen %', 'align' => 'text-right'],
            ],
            filas: $filas,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
