<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrdenCompra;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrdenCompraPdfController extends Controller
{
    public function __invoke(Request $request, OrdenCompra $ordenCompra): Response
    {
        $user = $request->user();
        abort_unless($user && $user->empresa_id === (int) $ordenCompra->empresa_id, 403);
        abort_unless($user->can('ordenes_compra.exportar'), 403);

        $ordenCompra->load(['empresa', 'proveedor', 'detalles.producto', 'user', 'aprobadoPor']);

        $pdf = Pdf::loadView('ordenes-compra.pdf', [
            'orden' => $ordenCompra,
            'empresa' => $ordenCompra->empresa,
        ]);

        return $pdf->stream("OC-{$ordenCompra->numero}.pdf");
    }
}
