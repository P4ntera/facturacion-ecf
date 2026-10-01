<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteComprasPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $compras = $servicio->comprasEnRangoQuery($desde, $hasta, $this->empresaId($request))
            ->orderBy('fecha')
            ->get();

        $registradas = $compras->where('estado', EstadoCompra::REGISTRADA);

        $filas = $compras->map(fn (Compra $compra) => [
            'fecha' => $compra->fecha->format('d/m/Y'),
            'proveedor' => $compra->proveedor?->nombre ?? '—',
            'ncf' => $compra->ncf ?? '—',
            'subtotal' => number_format((float) $compra->subtotal, 2),
            'itbis' => number_format((float) $compra->itbis, 2),
            'total' => number_format((float) $compra->total, 2),
            'estado' => $compra->estado === EstadoCompra::ANULADA ? 'Anulada' : 'Registrada',
            'usuario' => $compra->user?->name ?? '—',
        ])->all();

        return $this->responder(
            request: $request,
            titulo: 'Reporte de compras',
            columnas: [
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'proveedor', 'label' => 'Proveedor'],
                ['key' => 'ncf', 'label' => 'NCF'],
                ['key' => 'subtotal', 'label' => 'Subtotal', 'align' => 'text-right'],
                ['key' => 'itbis', 'label' => 'ITBIS', 'align' => 'text-right'],
                ['key' => 'total', 'label' => 'Total', 'align' => 'text-right'],
                ['key' => 'estado', 'label' => 'Estado'],
                ['key' => 'usuario', 'label' => 'Registrado por'],
            ],
            filas: $filas,
            totales: [
                'proveedor' => 'Totales (no incluye anuladas)',
                'subtotal' => number_format((float) $this->sumarBc($registradas, 'subtotal'), 2),
                'itbis' => number_format((float) $this->sumarBc($registradas, 'itbis'), 2),
                'total' => number_format((float) $this->sumarBc($registradas, 'total'), 2),
            ],
            desde: $desde,
            hasta: $hasta,
        );
    }
}
