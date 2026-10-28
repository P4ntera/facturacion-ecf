<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CotizacionPdfController extends Controller
{
    public function __invoke(Request $request, Cotizacion $cotizacion): Response
    {
        $user = $request->user();
        abort_unless($user && $user->perteneceAEmpresa($cotizacion->empresa_id), 403);
        abort_unless($user->can('cotizaciones.exportar'), 403);

        $cotizacion->load(['empresa.configuracion', 'cliente', 'detalles.producto', 'user']);

        $empresa = $cotizacion->empresa;
        $config = $empresa->config();

        $pdf = Pdf::loadView('cotizaciones.pdf', [
            'cotizacion' => $cotizacion,
            'empresa' => $empresa,
            'colorPrimario' => $config->color_primario ?? '#00C9D7',
            'colorSecundario' => $config->color_secundario ?? '#101820',
        ]);

        $pdf->setPaper('letter');

        return $pdf->stream("Cotizacion_{$cotizacion->numero}.pdf");
    }
}
