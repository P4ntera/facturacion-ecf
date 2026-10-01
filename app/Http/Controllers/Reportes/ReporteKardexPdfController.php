<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Enums\TipoMovimiento;
use App\Models\MovimientoInventario;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteKardexPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $movimientos = $servicio->kardexQuery($desde, $hasta, empresaId: $this->empresaId($request))
            ->orderBy('created_at')
            ->get();

        $filas = $movimientos->map(fn (MovimientoInventario $mov) => [
            'fecha' => $mov->created_at->format('d/m/Y H:i'),
            'producto' => $mov->producto?->nombre ?? '—',
            'tipo' => $mov->tipo->value,
            'origen' => $mov->origen->value,
            'cantidad' => ($mov->tipo === TipoMovimiento::SALIDA ? '-' : '+').number_format((float) $mov->cantidad, 2),
            'stock_anterior' => number_format((float) $mov->stock_anterior, 2),
            'stock_nuevo' => number_format((float) $mov->stock_nuevo, 2),
            'usuario' => $mov->user?->name ?? '—',
            'observacion' => $mov->observacion ?? '—',
        ])->all();

        return $this->responder(
            request: $request,
            titulo: 'Kardex de movimientos',
            columnas: [
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'producto', 'label' => 'Producto'],
                ['key' => 'tipo', 'label' => 'Tipo'],
                ['key' => 'origen', 'label' => 'Origen'],
                ['key' => 'cantidad', 'label' => 'Cantidad', 'align' => 'text-right'],
                ['key' => 'stock_anterior', 'label' => 'Stock Ant.', 'align' => 'text-right'],
                ['key' => 'stock_nuevo', 'label' => 'Stock Nuevo', 'align' => 'text-right'],
                ['key' => 'usuario', 'label' => 'Usuario'],
                ['key' => 'observacion', 'label' => 'Observación'],
            ],
            filas: $filas,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
