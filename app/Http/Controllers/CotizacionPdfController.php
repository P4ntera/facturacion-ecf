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
        abort_unless($user && $user->empresa_id === (int) $cotizacion->empresa_id, 403);
        abort_unless($user->can('cotizaciones.exportar'), 403);

        $cotizacion->load(['empresa', 'cliente', 'detalles.producto', 'user']);

        $pdf = Pdf::loadView('cotizaciones.pdf', [
            'cotizacion' => $cotizacion,
            'empresa' => $cotizacion->empresa,
        ]);

        return $pdf->stream("COT-{$cotizacion->numero}.pdf");
    }
}
