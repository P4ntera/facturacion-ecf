<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Services\ReporteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteFiscal607PdfController extends ReportePdfController
{
    public function __invoke(Request $request, ReporteService $servicio): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $filas607 = $servicio->reporte607($desde, $hasta);

        $filas = $filas607->map(fn (array $fila) => [
            'fecha' => $fila['fecha_comprobante']->format('d/m/Y'),
            'ncf' => $fila['numero_comprobante'],
            'ncf_modificado' => $fila['numero_comprobante_modificado'] ?? '—',
            'rnc_cedula' => $fila['rnc_cedula'] ?? '—',
            'tipo_identificacion' => $servicio->etiquetaTipoIdentificacion607($fila['tipo_identificacion']),
            'tipo_ingreso' => $fila['tipo_ingreso'],
            'monto_facturado' => number_format((float) $fila['monto_facturado'], 2),
            'itbis_facturado' => number_format((float) $fila['itbis_facturado'], 2),
            'monto_total' => number_format((float) $fila['monto_total'], 2),
        ])->all();

        $totales = [
            'ncf' => 'Totales',
            'monto_facturado' => number_format((float) $this->sumarBc($filas607, 'monto_facturado'), 2),
            'itbis_facturado' => number_format((float) $this->sumarBc($filas607, 'itbis_facturado'), 2),
            'monto_total' => number_format((float) $this->sumarBc($filas607, 'monto_total'), 2),
        ];

        return $this->responder(
            titulo: 'Formato 607 — Envío de ventas',
            columnas: [
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'ncf', 'label' => 'NCF'],
                ['key' => 'ncf_modificado', 'label' => 'NCF modificado'],
                ['key' => 'rnc_cedula', 'label' => 'RNC/Cédula'],
                ['key' => 'tipo_identificacion', 'label' => 'Tipo ident.'],
                ['key' => 'tipo_ingreso', 'label' => 'Tipo ingreso'],
                ['key' => 'monto_facturado', 'label' => 'Monto facturado', 'align' => 'text-right'],
                ['key' => 'itbis_facturado', 'label' => 'ITBIS facturado', 'align' => 'text-right'],
                ['key' => 'monto_total', 'label' => 'Monto total', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
