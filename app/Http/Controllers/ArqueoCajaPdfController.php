<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ArqueoCaja;
use App\Settings\EmpresaSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class ArqueoCajaPdfController extends Controller
{
    /** Genera el PDF del arqueo. Solo lee datos ya guardados; no recalcula nada. */
    public function __invoke(ArqueoCaja $arqueoCaja): Response
    {
        $arqueoCaja->load('ventas.cliente', 'user');

        $pdf = Pdf::loadView('arqueos-caja.arqueo', [
            'arqueo' => $arqueoCaja,
            'empresa' => app(EmpresaSettings::class),
        ]);

        return $pdf->stream("arqueo-caja-{$arqueoCaja->id}.pdf");
    }
}
