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
     * PedidoCompra todavía no tiene empresa_id propio (hueco de tenancy preexistente, señalado
     * en el T2 y fuera del alcance de T3): se usa la empresa del usuario autenticado para el
     * membrete en vez de $pedidoCompra->empresa, que no existe.
     */
    public function __invoke(Request $request, PedidoCompra $pedidoCompra): Response
    {
        $pedidoCompra->load('detalles.producto', 'proveedor', 'user');

        $pdf = Pdf::loadView('pedidos.pedido-compra-pdf', [
            'pedido' => $pedidoCompra,
            'empresa' => $request->user()->empresa,
        ]);

        return $pdf->stream("pedido-compra-{$pedidoCompra->id}.pdf");
    }
}
