<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\CuentaPorCobrar;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteCxcPdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $cuentas = $servicio->cuentasPorCobrarQuery($this->empresaId($request))
            ->orderBy('fecha_vencimiento')
            ->get();

        $filas = $cuentas->map(fn (CuentaPorCobrar $cuenta) => [
            'emision' => $cuenta->fecha_emision->format('d/m/Y'),
            'vencimiento' => $cuenta->fecha_vencimiento->format('d/m/Y'),
            'cliente' => $cuenta->cliente?->nombre ?? '—',
            'ncf' => $cuenta->venta?->ncf ?? '—',
            'monto_total' => number_format((float) $cuenta->monto_total, 2),
            'pagado' => number_format((float) $cuenta->monto_pagado, 2),
            'pendiente' => number_format($cuenta->montoPendiente(), 2),
        ])->all();

        $totalPendiente = $cuentas->reduce(
            fn (string $acc, CuentaPorCobrar $c) => bcadd($acc, (string) $c->montoPendiente(), 2),
            '0.00',
        );

        return $this->responder(
            request: $request,
            titulo: 'Cuentas por cobrar',
            columnas: [
                ['key' => 'emision', 'label' => 'Emisión'],
                ['key' => 'vencimiento', 'label' => 'Vencimiento'],
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'ncf', 'label' => 'NCF'],
                ['key' => 'monto_total', 'label' => 'Monto Total', 'align' => 'text-right'],
                ['key' => 'pagado', 'label' => 'Pagado', 'align' => 'text-right'],
                ['key' => 'pendiente', 'label' => 'Pendiente', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: [
                'cliente' => 'Total pendiente',
                'pendiente' => number_format((float) $totalPendiente, 2),
            ],
        );
    }
}
