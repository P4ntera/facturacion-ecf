<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Orden de Compra {{ $orden->numero }}</title>
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
        .firma { margin-top: 48px; text-align: center; }
        .firma-linea { display: inline-block; width: 200px; border-top: 1px solid #111827; margin-top: 40px; padding-top: 4px; font-size: 11px; }
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
                <p class="titulo">ORDEN DE COMPRA</p>
                <p><strong>{{ $orden->numero }}</strong></p>
                <p>Fecha: {{ $orden->fecha->format('d/m/Y') }}</p>
                @if ($orden->fecha_esperada)
                    <p>Entrega esperada: {{ $orden->fecha_esperada->format('d/m/Y') }}</p>
                @endif
                <p>Estado: {{ $orden->estado->etiqueta() }}</p>
            </td>
        </tr>
    </table>

    <div class="info-section">
        <p><span class="label">Proveedor:</span> {{ $orden->proveedor->nombre }}</p>
        @if ($orden->proveedor->rnc)
            <p><span class="label">RNC:</span> {{ $orden->proveedor->rnc }}</p>
        @endif
        @if ($orden->proveedor->direccion)
            <p><span class="label">Dirección:</span> {{ $orden->proveedor->direccion }}</p>
        @endif
        @if ($orden->proveedor->telefono)
            <p><span class="label">Teléfono:</span> {{ $orden->proveedor->telefono }}</p>
        @endif
    </div>

    <table class="lineas">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 40%;">Producto</th>
                <th class="text-right" style="width: 12%;">Cantidad</th>
                <th class="text-right" style="width: 15%;">Precio Unit.</th>
                <th class="text-right" style="width: 13%;">ITBIS</th>
                <th class="text-right" style="width: 15%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($orden->detalles as $i => $detalle)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $detalle->producto->nombre }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->cantidad_solicitada, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->itbis, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $detalle->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="text-right">Subtotal:</td>
                <td class="text-right">RD$ {{ number_format((float) $orden->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td colspan="5" class="text-right">ITBIS:</td>
                <td class="text-right">RD$ {{ number_format((float) $orden->itbis, 2) }}</td>
            </tr>
            <tr>
                <td colspan="5" class="text-right">Total:</td>
                <td class="text-right">RD$ {{ number_format((float) $orden->total, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($orden->notas)
        <div class="notas">
            <strong>Notas:</strong><br>
            {{ $orden->notas }}
        </div>
    @endif

    <div class="firma">
        <div class="firma-linea">Aprobado por</div>
    </div>

    <p class="footer">Generado el {{ now()->format('d/m/Y H:i') }}</p>
</body>
</html>
