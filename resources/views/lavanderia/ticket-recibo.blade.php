<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante {{ $ticket->numero }}</title>
    <style>
        @page { margin: 20px 24px; size: letter; }
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #111827; margin: 0; }
        table { width: 100%; border-collapse: collapse; }

        .header-table { margin-bottom: 12px; }
        .header-table td { vertical-align: top; }
        .logo { max-width: 80px; max-height: 80px; }
        .empresa-nombre { font-size: 15px; font-weight: bold; margin: 0 0 2px; }
        .empresa-datos { font-size: 10px; color: #374151; margin: 0; }

        .titulo { text-align: center; font-size: 14px; font-weight: bold; color: #1d4ed8; margin: 8px 0 12px; }

        .info-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 8px 10px;
            margin-bottom: 12px;
        }
        .info-box table td { padding: 2px 0; font-size: 11px; }
        .info-label { font-weight: bold; width: 160px; }

        .detalles th {
            background-color: #f3f4f6;
            text-align: left;
            padding: 5px 8px;
            font-size: 10px;
            border-bottom: 1px solid #d1d5db;
        }
        .detalles td {
            padding: 5px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10px;
        }
        .text-right { text-align: right; }

        .totales-box {
            margin-top: 8px;
            margin-left: auto;
            width: 240px;
        }
        .totales-box td {
            padding: 3px 8px;
            font-size: 11px;
        }
        .totales-box .total-final td {
            border-top: 2px solid #111827;
            font-weight: bold;
            font-size: 12px;
        }

        .observaciones {
            margin-top: 12px;
            padding: 8px 10px;
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            font-size: 10px;
        }
        .observaciones strong { font-size: 11px; }

        .fecha-prometida {
            margin-top: 10px;
            padding: 6px 10px;
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            font-size: 11px;
            color: #1e40af;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
        }
        .footer .aviso { font-weight: bold; font-size: 10px; color: #9ca3af; margin-bottom: 2px; }
    </style>
</head>
<body>
    {{-- Encabezado de la empresa --}}
    <table class="header-table">
        <tr>
            <td style="width: 90px;">
                @if ($empresa->logo)
                    @php $rutaLogo = \Illuminate\Support\Facades\Storage::disk('public')->path($empresa->logo); @endphp
                    @if (is_file($rutaLogo))
                        <img src="{{ $rutaLogo }}" class="logo" alt="Logo">
                    @endif
                @endif
            </td>
            <td>
                <p class="empresa-nombre">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</p>
                @if ($empresa->nombre_comercial && $empresa->nombre_comercial !== $empresa->razon_social)
                    <p class="empresa-datos">{{ $empresa->razon_social }}</p>
                @endif
                <p class="empresa-datos">RNC: {{ $empresa->rnc }}</p>
                @if ($empresa->direccion)
                    <p class="empresa-datos">{{ $empresa->direccion }}</p>
                @endif
                @if ($empresa->telefono)
                    <p class="empresa-datos">Tel: {{ $empresa->telefono }}</p>
                @endif
            </td>
        </tr>
    </table>

    <p class="titulo">Comprobante de Servicio de Lavandería</p>

    {{-- Información del ticket --}}
    <div class="info-box">
        <table>
            <tr>
                <td class="info-label">Número:</td>
                <td>{{ $ticket->numero }}</td>
                <td class="info-label">Fecha:</td>
                <td>{{ $ticket->created_at->format('d/m/Y H:i') }}</td>
            </tr>
            <tr>
                <td class="info-label">Cliente:</td>
                <td>{{ $ticket->cliente?->nombre ?? 'Consumidor Final' }}</td>
                <td class="info-label">Estado:</td>
                <td>{{ $ticket->estado->etiqueta() }}</td>
            </tr>
            <tr>
                <td class="info-label">Recepcionista:</td>
                <td>{{ $ticket->recepcionista?->name ?? '—' }}</td>
                <td class="info-label">Estado de pago:</td>
                <td>{{ $ticket->estado_pago->etiqueta() }}</td>
            </tr>
        </table>
    </div>

    @if ($ticket->fecha_prometida)
        <div class="fecha-prometida">
            <strong>Fecha estimada de entrega:</strong> {{ $ticket->fecha_prometida->format('d/m/Y H:i') }}
        </div>
    @endif

    {{-- Detalle de prendas --}}
    <table class="detalles" style="margin-top: 12px;">
        <thead>
            <tr>
                <th>Tipo de Prenda</th>
                <th>Servicio</th>
                <th class="text-right">Cant.</th>
                <th class="text-right">Precio Unit.</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ticket->detalles as $detalle)
                <tr>
                    <td>{{ $detalle->tipoPrenda->nombre }}</td>
                    <td>{{ $detalle->servicioLavanderia->nombre }}</td>
                    <td class="text-right">{{ $detalle->cantidad }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
                @if ($detalle->observaciones)
                    <tr>
                        <td colspan="5" style="padding-left: 16px; color: #6b7280; font-style: italic;">
                            {{ $detalle->observaciones }}
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    {{-- Totales --}}
    @php
        $subtotalNeto = $ticket->detalles->sum(fn ($d) => (float) $d->subtotal);
    @endphp
    <table class="totales-box">
        <tr>
            <td class="text-right">Subtotal:</td>
            <td class="text-right">RD$ {{ number_format($subtotalNeto, 2) }}</td>
        </tr>
        <tr>
            <td class="text-right">ITBIS (18%):</td>
            <td class="text-right">RD$ {{ number_format((float) $ticket->itbis, 2) }}</td>
        </tr>
        <tr class="total-final">
            <td class="text-right">TOTAL:</td>
            <td class="text-right">RD$ {{ number_format((float) $ticket->total, 2) }}</td>
        </tr>
    </table>

    {{-- Observaciones generales --}}
    @if ($ticket->observaciones)
        <div class="observaciones">
            <strong>Observaciones:</strong><br>
            {{ $ticket->observaciones }}
        </div>
    @endif

    {{-- Pie de página --}}
    <div class="footer">
        <p class="aviso">Este comprobante no tiene valor fiscal</p>
        <p>{{ $empresa->nombre_comercial ?: $empresa->razon_social }} — Generado el {{ now()->format('d/m/Y H:i') }}</p>
    </div>
</body>
</html>
