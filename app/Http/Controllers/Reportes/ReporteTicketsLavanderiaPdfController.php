<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Enums\EstadoTicketLavanderia;
use App\Models\TicketLavanderia;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteTicketsLavanderiaPdfController extends ReportePdfController
{
    public function __invoke(Request $request): Response
    {
        $desde = $this->rangoDesde($request);
        $hasta = $this->rangoHasta($request);

        $tickets = TicketLavanderia::query()
            ->where('empresa_id', $this->empresaId($request))
            ->whereBetween('created_at', [$desde, $hasta])
            ->with(['cliente', 'recepcionista'])
            ->orderBy('created_at')
            ->get();

        $noCancelados = $tickets->where('estado', '!=', EstadoTicketLavanderia::CANCELADO);

        $filas = $tickets->map(fn (TicketLavanderia $ticket) => [
            'numero' => $ticket->numero,
            'fecha' => $ticket->created_at->format('d/m/Y H:i'),
            'cliente' => $ticket->cliente?->nombre ?? 'Sin cliente',
            'recepcionista' => $ticket->recepcionista?->nombre ?? '—',
            'estado' => $ticket->estado->etiqueta(),
            'estado_pago' => $ticket->estado_pago->etiqueta(),
            'itbis' => number_format((float) $ticket->itbis, 2),
            'total' => number_format((float) $ticket->total, 2),
        ])->all();

        $totales = [
            'cliente' => 'Totales (no incluye cancelados)',
            'itbis' => number_format((float) $this->sumarBc($noCancelados, 'itbis'), 2),
            'total' => number_format((float) $this->sumarBc($noCancelados, 'total'), 2),
        ];

        return $this->responder(
            request: $request,
            titulo: 'Reporte de tickets de lavandería',
            columnas: [
                ['key' => 'numero', 'label' => 'Número'],
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'recepcionista', 'label' => 'Recepcionista'],
                ['key' => 'estado', 'label' => 'Estado'],
                ['key' => 'estado_pago', 'label' => 'Pago'],
                ['key' => 'itbis', 'label' => 'ITBIS', 'align' => 'text-right'],
                ['key' => 'total', 'label' => 'Total', 'align' => 'text-right'],
            ],
            filas: $filas,
            totales: $totales,
            desde: $desde,
            hasta: $hasta,
        );
    }
}
