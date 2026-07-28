<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PedidoCompra;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PedidoCompraPdfController extends Controller
{
    /**
     * Genera el PDF del pedido de compra. Solo lee datos ya guardados; no recalcula nada.
     *
     * Ruta fuera del panel de Filament: el scoping automático por tenant no aplica aquí (ver
     * BelongsToTenant de Filament), así que la pertenencia a la empresa se verifica a mano.
     */
    public function __invoke(Request $request, PedidoCompra $pedidoCompra): Response
    {
        abort_unless($request->user()->perteneceAEmpresa($pedidoCompra->empresa_id), 403);

        $pedidoCompra->load('detalles.producto', 'proveedor', 'user');

        $pdf = Pdf::loadView('pedidos.pedido-compra-pdf', [
            'pedido' => $pedidoCompra,
            'empresa' => $pedidoCompra->empresa,
        ]);

        return $pdf->stream("pedido-compra-{$pedidoCompra->id}.pdf");
    }
}
