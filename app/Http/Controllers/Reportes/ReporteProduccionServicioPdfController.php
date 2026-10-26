<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\DetalleTicketLavanderia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReporteProduccionServicioPdfController extends ReportePdfController
{
    public function __invoke(Request $request): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $registros = DetalleTicketLavanderia::query()
            ->join('tickets_lavanderia', 'detalle_tickets_lavanderia.ticket_lavanderia_id', '=', 'tickets_lavanderia.id')
            ->join('servicios_lavanderia', 'detalle_tickets_lavanderia.servicio_lavanderia_id', '=', 'servicios_lavanderia.id')
            ->join('tipos_prenda', 'detalle_tickets_lavanderia.tipo_prenda_id', '=', 'tipos_prenda.id')
            ->where('tickets_lavanderia.empresa_id', $this->empresaId($request))
            ->whereBetween('tickets_lavanderia.created_at', [$desde, $hasta])
            ->where('tickets_lavanderia.estado', '!=', 'cancelado')
            ->select(
                'servicios_lavanderia.nombre as servicio',
                'tipos_prenda.nombre as tipo_prenda',
                DB::raw('SUM(detalle_tickets_lavanderia.cantidad) as total_prendas'),
                DB::raw('SUM(detalle_tickets_lavanderia.subtotal) as total_ingresos'),
            )
            ->groupBy('servicios_lavanderia.nombre', 'tipos_prenda.nombre')
            ->orderBy('servicios_lavanderia.nombre')
            ->get();

        $filas = $registros->map(fn ($registro) => [
            'servicio' => $registro->servicio,
            'tipo_prenda' => $registro->tipo_prenda,
            'total_prendas' => number_format((int) $registro->total_prendas),
            'total_ingresos' => number_format((float) $registro->total_ingresos, 2),
        ])->all();

        $totales = [
            'tipo_prenda' => 'Totales',
            'total_prendas' => number_format($registros->sum('total_prendas')),
            'total_ingresos' => number_format((float) $registros->sum('total_ingresos'), 2),
        ];

        return $this->responder(
            request: $request,
            titulo: 'Reporte de producción por servicio',
            columnas: [
                ['key' => 'servicio', 'label' => 'Servicio'],
                ['key' => 'tipo_prenda', 'label' => 'Tipo de Prenda'],
                ['key' => 'total_prendas', 'label' => 'Total Prendas', 'align' => 'text-right'],
                ['key' => 'total_ingresos', 'label' => 'Total Ingresos', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
