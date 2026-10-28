<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $ticket->numero }}</title>
    @include('impresoras._estilos-ticket', ['anchoPapel' => $anchoPapel])
</head>
<body onload="window.print()">
    <div class="hoja">
        {{-- Encabezado empresa --}}
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

        <p class="centro negrita">SERVICIO DE LAVANDERIA</p>
        <p class="centro negrita">{{ $ticket->numero }}</p>

        <div class="separador"></div>

        {{-- Info del ticket --}}
        <p>Fecha: {{ $ticket->created_at->format('d/m/Y H:i') }}</p>
        <p>Cliente: {{ \Illuminate\Support\Str::limit($ticket->cliente?->nombre ?? 'Consumidor Final', $anchoPapel->columnas() - 9) }}</p>
        <p>Recep.: {{ \Illuminate\Support\Str::limit($ticket->recepcionista?->name ?? '—', $anchoPapel->columnas() - 8) }}</p>
        <p>Estado: {{ $ticket->estado->etiqueta() }}</p>
        <p>Pago: {{ $ticket->estado_pago->etiqueta() }}</p>

        @if ($ticket->fecha_prometida)
            <div class="separador"></div>
            <p class="centro negrita">ENTREGA ESTIMADA</p>
            <p class="centro">{{ $ticket->fecha_prometida->format('d/m/Y H:i') }}</p>
        @endif

        <div class="separador"></div>

        {{-- Detalle de prendas --}}
        @foreach ($ticket->detalles as $detalle)
            <p>{{ \Illuminate\Support\Str::limit($detalle->tipoPrenda->nombre, $anchoPapel->columnas()) }}</p>
            <p style="font-size: .85em; padding-left: 2ch;">{{ \Illuminate\Support\Str::limit($detalle->servicioLavanderia->nombre, $anchoPapel->columnas() - 2) }}</p>
            <table>
                <tr>
                    <td>{{ $detalle->cantidad }} x {{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
            </table>
            @if ($detalle->observaciones)
                <p style="font-size: .85em; padding-left: 2ch;">* {{ \Illuminate\Support\Str::limit($detalle->observaciones, $anchoPapel->columnas() - 4) }}</p>
            @endif
        @endforeach

        <div class="separador"></div>

        {{-- Totales --}}
        <table>
            <tr>
                <td>Subtotal</td>
                <td class="derecha">{{ number_format((float) $ticket->detalles->sum(fn ($d) => (float) $d->subtotal), 2) }}</td>
            </tr>
            <tr>
                <td>ITBIS (18%)</td>
                <td class="derecha">{{ number_format((float) $ticket->itbis, 2) }}</td>
            </tr>
        </table>

        <div class="separador"></div>

        <table>
            <tr>
                <td class="negrita">TOTAL</td>
                <td class="derecha negrita">RD$ {{ number_format((float) $ticket->total, 2) }}</td>
            </tr>
        </table>

        {{-- Observaciones generales --}}
        @if ($ticket->observaciones)
            <div class="separador"></div>
            <p class="negrita">Obs:</p>
            <p>{{ \Illuminate\Support\Str::limit($ticket->observaciones, $anchoPapel->columnas() * 3) }}</p>
        @endif

        <div class="separador"></div>
        <p class="centro">Este comprobante no tiene</p>
        <p class="centro">valor fiscal</p>
        <p class="centro">¡Gracias por preferirnos!</p>
    </div>
</body>
</html>
