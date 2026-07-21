<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Enums\EstadoVenta;
use App\Filament\Resources\VentaResource;
use App\Models\Venta;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteVentasPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $ventas = $servicio->ventasEnRangoQuery($desde, $hasta, $this->empresaId($request))
            ->with('cliente')
            ->orderBy('fecha')
            ->get();

        $emitidas = $ventas->where('estado', EstadoVenta::EMITIDA);

        $filas = $ventas->map(fn (Venta $venta) => [
            'fecha' => $venta->fecha->format('d/m/Y H:i'),
            'ncf' => $venta->ncf ?? '—',
            'cliente' => $venta->cliente->nombre,
            'tipo' => $venta->tipo_comprobante->etiqueta(),
            'subtotal' => number_format((float) $venta->subtotal, 2),
            'itbis' => number_format((float) $venta->total_itbis, 2),
            'total' => number_format((float) $venta->total, 2),
            'estado' => $venta->estado === EstadoVenta::ANULADA ? 'Anulada' : 'Emitida',
            'estado_fiscal' => VentaResource::etiquetaEstadoFiscal($venta->estado_fiscal),
        ])->all();

        $totales = [
            'cliente' => 'Totales (no incluye anuladas)',
            'subtotal' => number_format((float) $this->sumarBc($emitidas, 'subtotal'), 2),
            'itbis' => number_format((float) $this->sumarBc($emitidas, 'total_itbis'), 2),
            'total' => number_format((float) $this->sumarBc($emitidas, 'total'), 2),
        ];

        return $this->responder(
            titulo: 'Reporte de ventas',
            columnas: [
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'ncf', 'label' => 'e-NCF'],
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'tipo', 'label' => 'Tipo'],
                ['key' => 'subtotal', 'label' => 'Subtotal', 'align' => 'text-right'],
                ['key' => 'itbis', 'label' => 'ITBIS', 'align' => 'text-right'],
                ['key' => 'total', 'label' => 'Total', 'align' => 'text-right'],
                ['key' => 'estado', 'label' => 'Estado'],
                ['key' => 'estado_fiscal', 'label' => 'Estado fiscal'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
