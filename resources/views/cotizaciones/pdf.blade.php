<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->numero }}</title>
    <style>
        @page { margin: 24px 32px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .header-table { margin-bottom: 16px; }
        .header-table td { vertical-align: top; }
        .logo { max-width: 90px; max-height: 90px; }
        .empresa-nombre { font-size: 16px; font-weight: bold; margin: 0 0 2px; }
        .empresa-datos { font-size: 11px; color: #374151; margin: 0; }
        .doc-box { text-align: right; }
        .doc-box .titulo { font-weight: bold; font-size: 14px; margin: 0 0 4px; color: #1d4ed8; }
        .doc-box p { margin: 0 0 2px; font-size: 11px; }
        .info-section { margin-bottom: 16px; }
        .info-section p { margin: 0 0 2px; font-size: 12px; }
        .info-section .label { font-weight: bold; color: #374151; }
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
        .lineas tfoot td {
            border-top: 2px solid #111827;
            border-bottom: none;
            font-weight: bold;
            font-size: 11px;
        }
        .text-right { text-align: right; }
        .notas { margin-top: 16px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 11px; }
        .vigencia { margin-top: 16px; padding: 8px 12px; background-color: #fef3c7; border-radius: 4px; font-size: 11px; text-align: center; }
        .footer { margin-top: 24px; font-size: 10px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
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
            <td class="doc-box">
                <p class="titulo">COTIZACIÓN</p>
                <p><strong>{{ $cotizacion->numero }}</strong></p>
                <p>Fecha: {{ $cotizacion->fecha->format('d/m/Y') }}</p>
                <p>Vigencia: {{ $cotizacion->dias_vigencia }} días</p>
                <p>Vence: {{ $cotizacion->fecha_vencimiento->format('d/m/Y') }}</p>
            </td>
        </tr>
    </table>

    @if ($cotizacion->cliente)
        <div class="info-section">
            <p><span class="label">Cliente:</span> {{ $cotizacion->cliente->nombre }}</p>
            @if ($cotizacion->cliente->documento)
                <p><span class="label">{{ $cotizacion->cliente->tipo_documento?->etiqueta() ?? 'Documento' }}:</span> {{ $cotizacion->cliente->documento }}</p>
            @endif
            @if ($cotizacion->cliente->direccion)
                <p><span class="label">Dirección:</span> {{ $cotizacion->cliente->direccion }}</p>
            @endif
            @if ($cotizacion->cliente->telefono)
                <p><span class="label">Teléfono:</span> {{ $cotizacion->cliente->telefono }}</p>
            @endif
        </div>
    @endif

    @if ($cotizacion->condiciones_pago)
        <div class="info-section">
            <p><span class="label">Condiciones de pago:</span> {{ $cotizacion->condiciones_pago }}</p>
        </div>
    @endif

    <table class="lineas">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 35%;">Producto</th>
                <th class="text-right" style="width: 10%;">Cantidad</th>
                <th class="text-right" style="width: 15%;">Precio Unit.</th>
                <th class="text-right" style="width: 10%;">Descuento</th>
                <th class="text-right" style="width: 10%;">ITBIS</th>
                <th class="text-right" style="width: 15%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cotizacion->detalles as $i => $detalle)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $detalle->producto->nombre }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->cantidad, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->descuento, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->itbis, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="text-right">Subtotal:</td>
                <td class="text-right">RD$ {{ number_format((float) $cotizacion->subtotal, 2) }}</td>
            </tr>
            @if ((float) $cotizacion->descuento > 0)
                <tr>
                    <td colspan="6" class="text-right">Descuento:</td>
                    <td class="text-right">RD$ {{ number_format((float) $cotizacion->descuento, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="6" class="text-right">ITBIS:</td>
                <td class="text-right">RD$ {{ number_format((float) $cotizacion->itbis, 2) }}</td>
            </tr>
            <tr>
                <td colspan="6" class="text-right">Total:</td>
                <td class="text-right">RD$ {{ number_format((float) $cotizacion->total, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($cotizacion->notas)
        <div class="notas">
            <strong>Notas:</strong><br>
            {{ $cotizacion->notas }}
        </div>
    @endif

    <div class="vigencia">
        Esta cotización tiene vigencia hasta el <strong>{{ $cotizacion->fecha_vencimiento->format('d/m/Y') }}</strong>.
    </div>

    <p class="footer">Generado el {{ now()->format('d/m/Y H:i') }}</p>
</body>
</html>
