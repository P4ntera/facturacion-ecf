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
     * ArqueoCaja todavía no tiene empresa_id propio (hueco de tenancy preexistente, señalado en
     * el T2 y fuera del alcance de T3): se usa la empresa del usuario autenticado para el
     * membrete en vez de $arqueoCaja->empresa, que no existe.
     */
    public function __invoke(Request $request, ArqueoCaja $arqueoCaja): Response
    {
        $arqueoCaja->load('ventas.cliente', 'user');

        $pdf = Pdf::loadView('arqueos-caja.arqueo', [
            'arqueo' => $arqueoCaja,
            'empresa' => $request->user()->empresa,
        ]);

        return $pdf->stream("arqueo-caja-{$arqueoCaja->id}.pdf");
    }
}
