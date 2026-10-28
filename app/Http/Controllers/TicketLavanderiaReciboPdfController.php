<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TicketLavanderia;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TicketLavanderiaReciboPdfController extends Controller
{
    public function __invoke(Request $request, TicketLavanderia $ticket): Response
    {
        // Tenancy check: fuera del panel de Filament, verificar a mano que el ticket
        // pertenezca a la empresa del usuario autenticado (super-admin pasa siempre).
        abort_unless(
            $request->user()?->perteneceAEmpresa($ticket->empresa_id),
            403,
            'No autorizado',
        );

        $ticket->load([
            'empresa',
            'cliente',
            'recepcionista',
            'detalles.tipoPrenda',
            'detalles.servicioLavanderia',
        ]);

        $pdf = Pdf::loadView('lavanderia.ticket-recibo', [
            'ticket' => $ticket,
            'empresa' => $ticket->empresa,
        ]);

        return $pdf->stream("ticket-{$ticket->numero}.pdf");
    }
}
