<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Settings\EmpresaSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class VentaComprobanteController extends Controller
{
    /** Genera el PDF del comprobante. Solo lee datos ya guardados; no recalcula nada. */
    public function __invoke(Venta $venta): Response
    {
        $venta->load('detalles.producto', 'cliente');

        $pdf = Pdf::loadView('ventas.comprobante', [
            'venta' => $venta,
            'empresa' => app(EmpresaSettings::class),
        ]);

        return $pdf->stream("comprobante-{$venta->ncf}.pdf");
    }
}
