<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Arqueo de caja #{{ $arqueo->id }}</title>
    <style>
        @page { margin: 24px 32px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .header-table { margin-bottom: 16px; }
        .header-table td { vertical-align: top; }
        .logo { max-width: 90px; max-height: 90px; }
        .empresa-nombre { font-size: 16px; font-weight: bold; margin: 0 0 2px; }
        .empresa-datos { font-size: 11px; color: #374151; margin: 0; }
        .arqueo-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 8px 12px;
            text-align: right;
        }
        .arqueo-box .tipo { font-weight: bold; font-size: 13px; margin: 0 0 4px; }
        .arqueo-box .numero { font-size: 13px; font-weight: bold; color: #1d4ed8; margin: 0 0 4px; }
        .arqueo-box p { margin: 0; }
        .cajero-box { margin-bottom: 16px; }
        .cajero-box p { margin: 0 0 2px; }
        .resumen { margin-top: 8px; }
        .resumen th {
            background-color: #f3f4f6;
            text-align: left;
            padding: 6px 8px;
            font-size: 11px;
            border-bottom: 1px solid #d1d5db;
        }
        .resumen td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
        }
        .text-right { text-align: right; }
        .totales { margin-top: 12px; width: 280px; float: right; }
        .totales td { padding: 3px 8px; font-size: 11px; }
        .totales .diferencia td {
            border-top: 1px solid #111827;
            font-weight: bold;
            font-size: 13px;
        }
        .diferencia-negativa { color: #dc2626; }
        .diferencia-positiva { color: #16a34a; }
        .clear { clear: both; }
        .lineas { margin-top: 30px; }
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
            </td>
            <td style="width: 200px;">
                <div class="arqueo-box">
                    <p class="tipo">Arqueo de Caja</p>
                    <p class="numero">#{{ $arqueo->id }}</p>
                    <p>Abierto: {{ $arqueo->abierto_en->format('d/m/Y H:i') }}</p>
                    @if ($arqueo->cerrado_en)
                        <p>Cerrado: {{ $arqueo->cerrado_en->format('d/m/Y H:i') }}</p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="cajero-box">
        <p><strong>Cajero:</strong> {{ $arqueo->user->name }}</p>
        @if ($arqueo->notas)
            <p><strong>Notas:</strong> {{ $arqueo->notas }}</p>
        @endif
    </div>

    <table class="resumen">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="text-right">Monto</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Fondo inicial</td>
                <td class="text-right">RD$ {{ number_format((float) $arqueo->fondo_inicial, 2) }}</td>
            </tr>
            <tr>
                <td>Ventas en efectivo</td>
                <td class="text-right">RD$ {{ number_format((float) $arqueo->total_ventas_efectivo, 2) }}</td>
            </tr>
            <tr>
                <td>Ventas con tarjeta</td>
                <td class="text-right">RD$ {{ number_format((float) $arqueo->total_ventas_tarjeta, 2) }}</td>
            </tr>
            <tr>
                <td>Ventas por transferencia</td>
                <td class="text-right">RD$ {{ number_format((float) $arqueo->total_ventas_transferencia, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td>Efectivo esperado</td>
            <td class="text-right">RD$ {{ number_format((float) $arqueo->efectivo_esperado, 2) }}</td>
        </tr>
        <tr>
            <td>Efectivo contado</td>
            <td class="text-right">RD$ {{ number_format((float) $arqueo->efectivo_contado, 2) }}</td>
        </tr>
        <tr class="diferencia">
            <td>Diferencia</td>
            <td class="text-right {{ bccomp((string) $arqueo->diferencia, '0', 2) < 0 ? 'diferencia-negativa' : 'diferencia-positiva' }}">
                RD$ {{ number_format((float) $arqueo->diferencia, 2) }}
            </td>
        </tr>
    </table>

    <div class="clear"></div>

    <table class="lineas">
        <thead>
            <tr>
                <th>NCF</th>
                <th>Cliente</th>
                <th>Forma de pago</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($arqueo->ventas as $venta)
                <tr>
                    <td>{{ $venta->ncf ?? '—' }}</td>
                    <td>{{ $venta->cliente->nombre }}</td>
                    <td>{{ $venta->forma_pago->etiqueta() }}</td>
                    <td class="text-right">RD$ {{ number_format((float) $venta->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
