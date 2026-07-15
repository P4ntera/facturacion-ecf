<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $venta->ncf ?? $venta->id }}</title>
    @include('impresoras._estilos-ticket', ['anchoPapel' => $anchoPapel])
</head>
<body onload="window.print()">
    <div class="hoja">
        @if ($venta->estaAnulada())
            <p class="centro negrita">*** COMPROBANTE ANULADO ***</p>
            <div class="separador"></div>
        @endif

        <p class="centro negrita">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</p>
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

        <p class="centro negrita">{{ $venta->tipo_comprobante->etiqueta() }}</p>
        @if ($venta->ncf)
            <p class="centro negrita">{{ $venta->ncf }}</p>
        @endif
        <p>Fecha: {{ $venta->fecha->format('d/m/Y H:i') }}</p>
        <p>Cliente: {{ \Illuminate\Support\Str::limit($venta->cliente->nombre, $anchoPapel->columnas() - 9) }}</p>
        @if ($venta->cliente->documento)
            <p>Doc: {{ $venta->cliente->documento }}</p>
        @endif

        <div class="separador"></div>

        @foreach ($venta->detalles as $detalle)
            <p>{{ \Illuminate\Support\Str::limit($detalle->descripcion, $anchoPapel->columnas()) }}</p>
            <table>
                <tr>
                    <td>{{ rtrim(rtrim(number_format((float) $detalle->cantidad, 3, '.', ''), '0'), '.') }} x {{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
            </table>
        @endforeach

        <div class="separador"></div>

        <table>
            <tr>
                <td>Subtotal</td>
                <td class="derecha">{{ number_format((float) $venta->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td>ITBIS</td>
                <td class="derecha">{{ number_format((float) $venta->total_itbis, 2) }}</td>
            </tr>
        </table>

        <div class="separador"></div>

        <table>
            <tr>
                <td class="negrita">TOTAL</td>
                <td class="derecha negrita">{{ $venta->moneda }} {{ number_format((float) $venta->total, 2) }}</td>
            </tr>
        </table>

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
