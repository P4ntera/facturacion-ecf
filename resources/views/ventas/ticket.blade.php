<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $venta->ncf ?? $venta->id }}</title>
    @include('impresoras._estilos-ticket', ['anchoPapel' => $anchoPapel])
    <style>
        .nombre-empresa { font-size: 16px; font-weight: bold; }
        .titulo-comprobante { font-size: 14px; font-weight: bold; }
    </style>
</head>
<body onload="window.print()">
<script>window.onafterprint = function() { window.close(); };</script>
    <div class="hoja">
        @if ($venta->estaAnulada())
            <p class="centro negrita">*** COMPROBANTE ANULADO ***</p>
            <div class="separador"></div>
        @endif

        <p class="centro nombre-empresa">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</p>
        @if ($empresa->nombre_comercial && $empresa->nombre_comercial !== $empresa->razon_social)
            <p class="centro">{{ $empresa->razon_social }}</p>
        @endif
        <p class="centro">RNC: {{ $empresa->rnc }}</p>
        @if ($empresa->direccion)
            <p class="centro">{{ \Illuminate\Support\Str::limit($empresa->direccion, $anchoPapel->columnas()) }}</p>
        @endif
        @if ($empresa->telefono)
            <p class="centro">Tel: {{ $empresa->telefono }}</p>
        @endif

        <div class="separador"></div>

        <p class="centro titulo-comprobante">{{ $venta->etiquetaComprobante() }}</p>
        @if ($venta->ncf)
            <p class="centro negrita">{{ $venta->ncf }}</p>
        @endif
        <p>Fecha: {{ $venta->fecha->format('d/m/Y H:i') }}</p>
        <p>Cliente: {{ \Illuminate\Support\Str::limit($venta->nombreCliente(), $anchoPapel->columnas() - 9) }}</p>
        @if ($venta->cliente?->documento)
            <p>Doc: {{ $venta->cliente->documento }}</p>
        @endif
        @if ($venta->user)
            <p>Vendedor: {{ \Illuminate\Support\Str::limit($venta->user->name, $anchoPapel->columnas() - 10) }}</p>
        @endif

        <div class="separador"></div>

        @foreach ($venta->detalles as $detalle)
            <p>{{ \Illuminate\Support\Str::limit($detalle->descripcion, $anchoPapel->columnas()) }}</p>
            <table>
                <tr>
                    <td>{{ rtrim(rtrim(number_format((float) $detalle->cantidad, 3, '.', ''), '0'), '.') }} x {{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
                @if ((float) $detalle->descuento > 0)
                    <tr class="itbis-linea">
                        <td>Desc.</td>
                        <td class="derecha">-{{ number_format((float) $detalle->descuento, 2) }}</td>
                    </tr>
                @endif
                @if ((float) $detalle->itbis_monto > 0)
                    <tr class="itbis-linea">
                        <td>ITBIS {{ $detalle->tasa_itbis->value }}%</td>
                        <td class="derecha">{{ number_format((float) $detalle->itbis_monto, 2) }}</td>
                    </tr>
                @endif
            </table>
        @endforeach

        <div class="separador"></div>

        <table>
            <tr>
                <td>Subtotal</td>
                <td class="derecha">{{ number_format((float) $venta->subtotalNeto(), 2) }}</td>
            </tr>
            @if ((float) $venta->total_itbis > 0)
                <tr>
                    <td>ITBIS</td>
                    <td class="derecha">{{ number_format((float) $venta->total_itbis, 2) }}</td>
                </tr>
            @endif
        </table>

        <div class="separador"></div>

        <table>
            <tr>
                <td class="negrita">TOTAL</td>
                <td class="derecha negrita">{{ $venta->moneda }} {{ number_format((float) $venta->total, 2) }}</td>
            </tr>
        </table>
        @if ((float) $venta->descuento > 0)
            <p class="centro">Incluye descuento de {{ $venta->moneda }} {{ number_format((float) $venta->descuento, 2) }}</p>
        @endif

        {{-- Forma de pago --}}
        <div class="separador"></div>
        @if ($venta->forma_pago)
            <p>Pago: {{ $venta->forma_pago->etiqueta() }}</p>
        @endif
        @if ($venta->forma_pago === \App\Enums\FormaPago::EFECTIVO && (float) ($venta->monto_efectivo ?? 0) > 0)
            <p>Recibido: {{ $venta->moneda }} {{ number_format((float) $venta->monto_efectivo, 2) }}</p>
            @if ((float) ($venta->vuelto ?? 0) > 0)
                <p>Vuelto: {{ $venta->moneda }} {{ number_format((float) $venta->vuelto, 2) }}</p>
            @endif
        @elseif ($venta->forma_pago === \App\Enums\FormaPago::MIXTO)
            @if ((float) ($venta->monto_efectivo ?? 0) > 0)
                <p>  Efectivo: {{ $venta->moneda }} {{ number_format((float) $venta->monto_efectivo, 2) }}</p>
            @endif
            @if ((float) ($venta->monto_tarjeta ?? 0) > 0)
                <p>  Tarjeta: {{ $venta->moneda }} {{ number_format((float) $venta->monto_tarjeta, 2) }}</p>
            @endif
            @if ((float) ($venta->monto_transferencia ?? 0) > 0)
                <p>  Transferencia: {{ $venta->moneda }} {{ number_format((float) $venta->monto_transferencia, 2) }}</p>
            @endif
            @if ((float) ($venta->vuelto ?? 0) > 0)
                <p>Vuelto: {{ $venta->moneda }} {{ number_format((float) $venta->vuelto, 2) }}</p>
            @endif
        @endif

        @if ($qrTimbre)
            <div class="separador"></div>
            <img src="data:image/png;base64,{{ $qrTimbre }}" class="qr" alt="QR del timbre fiscal">
            <p class="centro">Código de seguridad:</p>
            <p class="centro negrita">{{ $venta->codigo_seguridad }}</p>
        @endif

        <div class="separador"></div>
        <p class="centro">¡Gracias por su compra!</p>
    </div>
</body>
</html>
