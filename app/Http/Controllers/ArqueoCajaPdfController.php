<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ArqueoCaja;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ArqueoCajaPdfController extends Controller
{
    /**
     * Genera el PDF del arqueo. Solo lee datos ya guardados; no recalcula nada.
     *
     * Ruta fuera del panel de Filament: el scoping automático por tenant no aplica aquí (ver
     * BelongsToTenant de Filament), así que la pertenencia a la empresa se verifica a mano.
     */
    public function __invoke(Request $request, ArqueoCaja $arqueoCaja): Response
    {
        abort_unless($request->user()->perteneceAEmpresa($arqueoCaja->empresa_id), 403);

        $arqueoCaja->load('ventas.cliente', 'user');

        $pdf = Pdf::loadView('arqueos-caja.arqueo', [
            'arqueo' => $arqueoCaja,
            'empresa' => $arqueoCaja->empresa,
        ]);

        return $pdf->stream("arqueo-caja-{$arqueoCaja->id}.pdf");
    }
}
