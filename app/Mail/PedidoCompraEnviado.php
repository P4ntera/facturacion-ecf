<?php

namespace App\Mail;

use App\Models\PedidoCompra;
use App\Settings\EmpresaSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PedidoCompraEnviado extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PedidoCompra $pedido) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Pedido de compra #{$this->pedido->id}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pedido-compra-enviado',
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $pedido = $this->pedido->loadMissing('detalles.producto', 'proveedor');

        $pdf = Pdf::loadView('pedidos.pedido-compra-pdf', [
            'pedido'  => $pedido,
            'empresa' => app(EmpresaSettings::class),
        ]);

        return [
            Attachment::fromData(fn () => $pdf->output(), "pedido-compra-{$pedido->id}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
