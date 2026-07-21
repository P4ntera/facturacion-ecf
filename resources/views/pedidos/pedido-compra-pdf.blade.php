<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pedido de compra #{{ $pedido->id }}</title>
    <style>
        @page { margin: 24px 32px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .header-table { margin-bottom: 16px; }
        .header-table td { vertical-align: top; }
        .logo { max-width: 90px; max-height: 90px; }
        .empresa-nombre { font-size: 16px; font-weight: bold; margin: 0 0 2px; }
        .empresa-datos { font-size: 11px; color: #374151; margin: 0; }
        .pedido-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 8px 12px;
            text-align: right;
        }
        .pedido-box .tipo { font-weight: bold; font-size: 13px; margin: 0 0 4px; }
        .pedido-box .numero { font-size: 13px; font-weight: bold; color: #1d4ed8; margin: 0 0 4px; }
        .pedido-box p { margin: 0; }
        .cancelado-banner {
            border: 2px solid #dc2626;
            color: #dc2626;
            font-weight: bold;
            text-align: center;
            padding: 6px;
            margin-bottom: 12px;
            letter-spacing: 2px;
        }
        .proveedor-box { margin-bottom: 16px; }
        .proveedor-box p { margin: 0 0 2px; }
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
        .disclaimer { margin-top: 30px; font-size: 10px; color: #6b7280; text-align: center; font-style: italic; }
    </style>
</head>
<body>
    @if ($pedido->estaCancelado())
        <div class="cancelado-banner">PEDIDO CANCELADO</div>
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
                <div class="pedido-box">
                    <p class="tipo">Pedido de Compra</p>
                    <p class="numero">#{{ $pedido->id }}</p>
                    <p>Fecha: {{ $pedido->fecha->format('d/m/Y H:i') }}</p>
                </div>
            </td>
        </tr>
    </table>

    <div class="proveedor-box">
        <p><strong>Proveedor:</strong> {{ $pedido->proveedor->nombre }}</p>
        @if ($pedido->proveedor->rnc)
            <p><strong>RNC / Cédula:</strong> {{ $pedido->proveedor->rnc }}</p>
        @endif
        @if ($pedido->notas)
            <p><strong>Notas:</strong> {{ $pedido->notas }}</p>
        @endif
    </div>

    <table class="lineas">
        <thead>
            <tr>
                <th>Producto</th>
                <th class="text-right">Cantidad</th>
                <th class="text-right">Costo estimado</th>
                <th class="text-right">ITBIS</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pedido->detalles as $detalle)
                <tr>
                    <td>{{ $detalle->producto->nombre }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->cantidad, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->costo_unitario, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->itbis_monto, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">RD$ {{ number_format((float) $pedido->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>ITBIS</td>
            <td class="text-right">RD$ {{ number_format((float) $pedido->itbis, 2) }}</td>
        </tr>
        <tr class="total-final">
            <td>Total</td>
            <td class="text-right">RD$ {{ number_format((float) $pedido->total, 2) }}</td>
        </tr>
    </table>

    <div class="clear"></div>

    <div class="disclaimer">
        Este documento es una solicitud de compra estimada; no constituye una factura ni un comprobante fiscal.
    </div>

    @if ($pedido->estaCancelado())
        <div class="footer">
            Este pedido fue cancelado{{ $pedido->cancelado_en ? ' el ' . $pedido->cancelado_en->format('d/m/Y H:i') : '' }}.
            @if ($pedido->motivo_cancelacion)
                Motivo: {{ $pedido->motivo_cancelacion }}
            @endif
        </div>
    @endif
</body>
</html>
