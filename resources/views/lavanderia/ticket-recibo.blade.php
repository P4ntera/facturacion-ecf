<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $ticket->numero }}</title>
    @include('impresoras._estilos-ticket', ['anchoPapel' => $anchoPapel])
    <style>
        /* Lavandería: fuente más legible y margen derecho anti-corte */
        body { font-size: 13px; padding-right: 3mm; }
        .detalle-tabla th {
            font-weight: bold;
            font-size: 12px;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            text-align: left;
        }
        .detalle-tabla th.derecha { text-align: right; }
        .detalle-tabla td { padding: 1px 0; }
        .detalle-servicio {
            font-size: .85em;
            padding-left: 1ch;
            margin: 0 0 1px;
        }
    </style>
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
        <table class="detalle-tabla">
            <thead>
                <tr>
                    <th>Prenda</th>
                    <th class="derecha">Cant.</th>
                    <th class="derecha">Costo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ticket->detalles as $detalle)
                    <tr>
                        <td>{{ \Illuminate\Support\Str::limit($detalle->tipoPrenda->nombre, $anchoPapel->columnas() - 16) }}</td>
                        <td class="derecha">{{ $detalle->cantidad }}</td>
                        <td class="derecha">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td colspan="3" class="detalle-servicio">{{ \Illuminate\Support\Str::limit($detalle->servicioLavanderia->nombre, $anchoPapel->columnas() - 2) }} · {{ number_format((float) $detalle->precio_unitario, 2) }} c/u</td>
                    </tr>
                    @if ($detalle->observaciones)
                        <tr>
                            <td colspan="3" class="detalle-servicio">* {{ \Illuminate\Support\Str::limit($detalle->observaciones, $anchoPapel->columnas() - 4) }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

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
