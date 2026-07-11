<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Services\Dgii\DgiiGatewayInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class VentaEcfXmlController extends Controller
{
    /** Descarga el XML firmado: redirige a xml_url si ya la tenemos, o la pide al PAC. */
    public function __invoke(Venta $venta): Response|RedirectResponse
    {
        if ($venta->xml_url !== null) {
            return redirect()->away($venta->xml_url);
        }

        abort_if($venta->pac_id === null, 404, 'Esta venta todavía no se ha enviado al PAC.');

        $xml = app(DgiiGatewayInterface::class)->descargarXml($venta->pac_id);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"ecf-{$venta->ncf}.xml\"",
        ]);
    }
}
