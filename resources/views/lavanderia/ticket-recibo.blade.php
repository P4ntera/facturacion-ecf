<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $ticket->numero }}</title>
    @include('impresoras._estilos-ticket', ['anchoPapel' => $anchoPapel])
    <style>
        body { font-size: 13px; padding-right: 3mm; }

        .nombre-empresa {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: .5px;
        }

        .titulo-ticket {
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .numero-ticket {
            font-size: 16px;
            font-weight: bold;
        }

        .detalle-tabla th {
            font-weight: bold;
            font-size: 13px;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            text-align: left;
        }
        .detalle-tabla th.derecha { text-align: right; }
        .detalle-tabla td { padding: 2px 0; font-size: 13px; }
        .detalle-servicio {
            font-size: .9em;
            padding-left: 1ch;
            margin: 0 0 1px;
        }

        .total-final td {
            font-size: 16px;
            font-weight: bold;
            padding-top: 2px;
        }

        .info-ticket p {
            font-size: 12px;
            margin: 1px 0;
        }

        .entrega-estimada {
            font-size: 14px;
            font-weight: bold;
        }

        .totales td { font-size: 13px; }
    </style>
</head>
<body onload="window.print()">
<script>window.onafterprint = function() { window.close(); };</script>
    <div class="hoja">
        {{-- Encabezado empresa --}}
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

        <p class="centro titulo-ticket">SERVICIO DE LAVANDERÍA</p>
        <p class="centro numero-ticket">{{ $ticket->numero }}</p>

        <div class="separador"></div>

        {{-- Info del ticket --}}
        <div class="info-ticket">
            <p>Fecha: {{ $ticket->created_at->format('d/m/Y H:i') }}</p>
            <p>Cliente: {{ \Illuminate\Support\Str::limit($ticket->cliente?->nombre ?? 'Consumidor Final', $anchoPapel->columnas() - 9) }}</p>
            <p>Recep.: {{ \Illuminate\Support\Str::limit($ticket->recepcionista?->name ?? '—', $anchoPapel->columnas() - 8) }}</p>
            <p>Estado: {{ $ticket->estado->etiqueta() }}</p>
            <p>Pago: {{ $ticket->estado_pago->etiqueta() }}</p>
        </div>

        @if ($ticket->fecha_prometida)
            <div class="separador"></div>
            <p class="centro entrega-estimada">ENTREGA ESTIMADA</p>
            <p class="centro" style="font-size: 13px;">{{ $ticket->fecha_prometida->format('d/m/Y H:i') }}</p>
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
        <table class="totales">
            <tr>
                <td>Subtotal</td>
                <td class="derecha">{{ number_format((float) $ticket->detalles->sum(fn ($d) => (float) $d->subtotal), 2) }}</td>
            </tr>
            @if ((float) $ticket->itbis > 0)
                <tr>
                    <td>ITBIS (18%)</td>
                    <td class="derecha">{{ number_format((float) $ticket->itbis, 2) }}</td>
                </tr>
            @endif
        </table>

        <div class="separador"></div>

        <table>
            <tr class="total-final">
                <td>TOTAL</td>
                <td class="derecha">RD$ {{ number_format((float) $ticket->total, 2) }}</td>
            </tr>
        </table>

        {{-- Forma de pago si ya fue cobrado --}}
        @if ($ticket->venta)
            <div class="separador"></div>
            <p style="font-size: 12px;">Forma de pago: {{ $ticket->venta->forma_pago?->etiqueta() ?? '—' }}</p>
            @if ($ticket->venta->forma_pago === \App\Enums\FormaPago::EFECTIVO && $ticket->venta->monto_efectivo)
                <p style="font-size: 12px;">Recibido: RD$ {{ number_format((float) $ticket->venta->monto_efectivo, 2) }}</p>
                @if ((float) ($ticket->venta->vuelto ?? 0) > 0)
                    <p style="font-size: 12px;">Vuelto: RD$ {{ number_format((float) $ticket->venta->vuelto, 2) }}</p>
                @endif
            @endif
        @endif

        {{-- Observaciones generales --}}
        @if ($ticket->observaciones)
            <div class="separador"></div>
            <p class="negrita" style="font-size: 12px;">Obs:</p>
            <p style="font-size: 12px;">{{ \Illuminate\Support\Str::limit($ticket->observaciones, $anchoPapel->columnas() * 3) }}</p>
        @endif

        <div class="separador"></div>
        <p class="centro" style="font-size: 12px;">¡Gracias por preferirnos!</p>
    </div>
</body>
</html>
