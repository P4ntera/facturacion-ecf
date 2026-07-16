<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnchoPapel;
use App\Models\Venta;
use App\Settings\EmpresaSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Ticket térmico (80mm/58mm) para impresión NAVEGADOR: el navegador decide la impresora física,
 * esta vista solo controla el formato y el contenido, y dispara window.print() al cargar.
 */
class VentaTicketController extends Controller
{
    public function __invoke(Request $request, Venta $venta): View
    {
        $venta->load('detalles.producto', 'cliente');

        return view('ventas.ticket', [
            'venta' => $venta,
            'empresa' => app(EmpresaSettings::class),
            'anchoPapel' => AnchoPapel::tryFrom((string) $request->query('ancho')) ?? AnchoPapel::MM80,
            'qrTimbre' => $venta->dgii_url !== null
                ? base64_encode((string) QrCode::format('png')->size(120)->generate($venta->dgii_url))
                : null,
        ]);
    }
}
