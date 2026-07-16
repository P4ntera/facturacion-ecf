<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 24px 32px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .header-table { margin-bottom: 16px; }
        .header-table td { vertical-align: top; }
        .logo { max-width: 90px; max-height: 90px; }
        .empresa-nombre { font-size: 16px; font-weight: bold; margin: 0 0 2px; }
        .empresa-datos { font-size: 11px; color: #374151; margin: 0; }
        .reporte-box { text-align: right; }
        .reporte-box .titulo { font-weight: bold; font-size: 14px; margin: 0 0 4px; color: #1d4ed8; }
        .reporte-box p { margin: 0 0 2px; font-size: 11px; }
        .resumen-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 16px;
        }
        .resumen-box p { margin: 0 0 2px; font-size: 12px; }
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
            </td>
            <td style="width: 220px;">
                <div class="reporte-box">
                    <p class="titulo">{{ $titulo }}</p>
                    @if ($desde && $hasta)
                        <p>Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}</p>
                    @endif
                    <p>Generado: {{ now()->format('d/m/Y H:i') }}</p>
                </div>
            </td>
        </tr>
    </table>

    @if (!empty($resumen))
        <div class="resumen-box">
            @foreach ($resumen as $etiqueta => $valor)
                <p><strong>{{ $etiqueta }}:</strong> {{ $valor }}</p>
            @endforeach
        </div>
    @endif

    <table class="lineas">
        <thead>
            <tr>
                @foreach ($columnas as $columna)
                    <th class="{{ $columna['align'] ?? '' }}">{{ $columna['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    @foreach ($columnas as $columna)
                        <td class="{{ $columna['align'] ?? '' }}">{{ $fila[$columna['key']] ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columnas) }}" class="text-right">Sin resultados</td>
                </tr>
            @endforelse
        </tbody>
        @if (!empty($totales))
            <tfoot>
                <tr>
                    @foreach ($columnas as $columna)
                        <td class="{{ $columna['align'] ?? '' }}">{{ $totales[$columna['key']] ?? '' }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    <p class="footer">{{ $empresa->nombre_comercial ?: $empresa->razon_social }} — Reporte generado desde el sistema de facturación</p>
</body>
</html>
