<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante {{ $venta->ncf }}</title>
    <style>
        @page { margin: 24px 32px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .header-table { margin-bottom: 16px; }
        .header-table td { vertical-align: top; }
        .logo { max-width: 90px; max-height: 90px; }
        .empresa-nombre { font-size: 16px; font-weight: bold; margin: 0 0 2px; }
        .empresa-datos { font-size: 11px; color: #374151; margin: 0; }
        .comprobante-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 8px 12px;
            text-align: right;
        }
        .comprobante-box .tipo { font-weight: bold; font-size: 13px; margin: 0 0 4px; }
        .comprobante-box .ncf { font-size: 13px; font-weight: bold; color: #1d4ed8; margin: 0 0 4px; }
        .comprobante-box p { margin: 0; }
        .anulada-banner {
            border: 2px solid #dc2626;
            color: #dc2626;
            font-weight: bold;
            text-align: center;
            padding: 6px;
            margin-bottom: 12px;
            letter-spacing: 2px;
        }
        .cliente-box { margin-bottom: 16px; }
        .cliente-box p { margin: 0 0 2px; }
        .lineas { margin-top: 8px; }
        .lineas th {
            background-color: #f3f4f6;
            text-align: left;
            padding: 6px 8px;
            font-size: 11px;
            border-bottom: 1px solid #d1d5db;
        }
        .lineas td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
        }
        .text-right { text-align: right; }
        .totales { margin-top: 12px; width: 260px; float: right; }
        .totales td { padding: 3px 8px; font-size: 11px; }
        .totales .total-final td {
            border-top: 1px solid #111827;
            font-weight: bold;
            font-size: 13px;
        }
        .clear { clear: both; }
        .footer { margin-top: 40px; font-size: 10px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    @if ($venta->estaAnulada())
        <div class="anulada-banner">COMPROBANTE ANULADO</div>
    @endif

    <table class="header-table">
        <tr>
            <td style="width: 100px;">
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
            <td style="width: 200px;">
                <div class="comprobante-box">
                    <p class="tipo">{{ $venta->tipo_comprobante->etiqueta() }}</p>
                    <p class="ncf">{{ $venta->ncf }}</p>
                    <p>Fecha: {{ $venta->fecha->format('d/m/Y H:i') }}</p>
                </div>
            </td>
        </tr>
    </table>

    <div class="cliente-box">
        <p><strong>Cliente:</strong> {{ $venta->cliente->nombre }}</p>
        @if ($venta->cliente->documento)
            <p><strong>Documento:</strong> {{ $venta->cliente->documento }}</p>
        @endif
    </div>

    <table class="lineas">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="text-right">Cantidad</th>
                <th class="text-right">Precio</th>
                <th class="text-right">ITBIS</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($venta->detalles as $detalle)
                <tr>
                    <td>{{ $detalle->descripcion }}</td>
                    <td class="text-right">{{ rtrim(rtrim(number_format((float) $detalle->cantidad, 3, '.', ''), '0'), '.') }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->itbis_monto, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">{{ $venta->moneda }} {{ number_format((float) $venta->subtotal, 2) }}</td>
        </tr>
        @if ((float) $venta->itbis_18 > 0)
            <tr>
                <td>ITBIS 18%</td>
                <td class="text-right">{{ $venta->moneda }} {{ number_format((float) $venta->itbis_18, 2) }}</td>
            </tr>
        @endif
        @if ((float) $venta->itbis_16 > 0)
            <tr>
                <td>ITBIS 16%</td>
                <td class="text-right">{{ $venta->moneda }} {{ number_format((float) $venta->itbis_16, 2) }}</td>
            </tr>
        @endif
        @if ((float) $venta->monto_gravado_0 > 0 || (float) $venta->monto_exento > 0)
            <tr>
                <td>Exento</td>
                <td class="text-right">{{ $venta->moneda }} {{ number_format((float) $venta->monto_gravado_0 + (float) $venta->monto_exento, 2) }}</td>
            </tr>
        @endif
        @if ((float) $venta->descuento > 0)
            <tr>
                <td>Descuento</td>
                <td class="text-right">-{{ $venta->moneda }} {{ number_format((float) $venta->descuento, 2) }}</td>
            </tr>
        @endif
        <tr class="total-final">
            <td>Total</td>
            <td class="text-right">{{ $venta->moneda }} {{ number_format((float) $venta->total, 2) }}</td>
        </tr>
    </table>

    <div class="clear"></div>

    @if ($venta->estaAnulada())
        <div class="footer">
            Este comprobante fue anulado{{ $venta->anulada_en ? ' el ' . $venta->anulada_en->format('d/m/Y H:i') : '' }}.
            @if ($venta->motivo_anulacion)
                Motivo: {{ $venta->motivo_anulacion }}
            @endif
        </div>
    @endif
</body>
</html>
