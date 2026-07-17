<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PedidoCompra;
use App\Settings\EmpresaSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class PedidoCompraPdfController extends Controller
{
    /** Genera el PDF del pedido de compra. Solo lee datos ya guardados; no recalcula nada. */
    public function __invoke(PedidoCompra $pedidoCompra): Response
    {
        $pedidoCompra->load('detalles.producto', 'proveedor', 'user');

        $pdf = Pdf::loadView('pedidos.pedido-compra-pdf', [
            'pedido'  => $pedidoCompra,
            'empresa' => app(EmpresaSettings::class),
        ]);

        return $pdf->stream("pedido-compra-{$pedidoCompra->id}.pdf");
    }
}
