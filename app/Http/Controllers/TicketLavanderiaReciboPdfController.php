<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnchoPapel;
use App\Models\TicketLavanderia;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Ticket térmico (80mm/58mm) del recibo de lavandería: vista HTML con window.print().
 * Mismo patrón que VentaTicketController — el navegador decide la impresora física.
 */
class TicketLavanderiaReciboPdfController extends Controller
{
    public function __invoke(Request $request, TicketLavanderia $ticket): View
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
            'venta.cliente',
        ]);

        // Timbre fiscal: solo con el e-CF ya aceptado (igual que el ticket de venta).
        $venta = $ticket->venta;
        $qrTimbre = $venta !== null && $venta->dgii_url !== null && $venta->estado_fiscal?->esAceptado()
            ? base64_encode((string) QrCode::format('png')->size(120)->generate($venta->dgii_url))
            : null;

        return view('lavanderia.ticket-recibo', [
            'ticket' => $ticket,
            'empresa' => $ticket->empresa,
            'qrTimbre' => $qrTimbre,
            'anchoPapel' => AnchoPapel::tryFrom((string) $request->query('ancho')) ?? AnchoPapel::MM80,
        ]);
    }
}
