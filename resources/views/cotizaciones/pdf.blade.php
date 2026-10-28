@php
    use Illuminate\Support\Facades\Storage;

    $nombreEmpresa = $empresa->nombre_comercial ?: $empresa->razon_social;
    $fechaFormateada = $cotizacion->fecha->translatedFormat('d \d\e F \d\e Y');

    $logoBase64 = null;
    if ($empresa->logo) {
        $rutaLogo = Storage::disk('public')->path($empresa->logo);
        if (is_file($rutaLogo)) {
            $mime = mime_content_type($rutaLogo) ?: 'image/png';
            $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($rutaLogo));
        }
    }

    $observacionesDefault = 'Los plazos de entrega, forma de pago y vigencia de esta cotización quedan sujetos a confirmación entre las partes.';
    $observaciones = $cotizacion->notas ?: $observacionesDefault;

    $incluyeDefault = 'Instalación completa del sistema, capacitación y 30 días de soporte post-instalación por WhatsApp o asistencia remota para dudas y ajustes.';
    $noIncluyeDefault = 'Mejoras, nuevas funcionalidades o modificaciones futuras no contempladas en el alcance inicial. Estos trabajos se cotizarán y cobrarán por separado.';

    $cp = $colorPrimario;
    $cs = $colorSecundario;

    // DomPDF doesn't support hex+alpha (e.g. #00C9D710), so compute a 10% tint over white.
    $r = hexdec(substr($cp, 1, 2));
    $g = hexdec(substr($cp, 3, 2));
    $b = hexdec(substr($cp, 5, 2));
    $lightAccent = sprintf('#%02X%02X%02X',
        (int) round($r * 0.1 + 255 * 0.9),
        (int) round($g * 0.1 + 255 * 0.9),
        (int) round($b * 0.1 + 255 * 0.9),
    );
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->numero }}</title>
    <style>
        @page {
            margin: 13mm 18mm 15mm 18mm;
            size: letter;
        }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            color: {{ $cs }};
            margin: 0;
            padding: 0;
        }
        table { width: 100%; border-collapse: collapse; }

        .header-table td { vertical-align: top; }
        .logo { max-width: 100px; max-height: 70px; }
        .doc-title {
            font-size: 21px;
            font-weight: bold;
            color: {{ $cs }};
            margin: 0;
        }
        .doc-meta {
            font-size: 8.5px;
            color: #5B6670;
            margin: 1px 0;
        }
        .header-line {
            border: none;
            border-top: 2px solid {{ $cp }};
            margin: 8px 0 10px 0;
        }

        .client-block {
            background-color: #F4F7F8;
            border: 0.5px solid #D9E2E7;
            padding: 8px;
            margin-bottom: 7px;
        }
        .client-block td {
            font-size: 8.5px;
            vertical-align: top;
        }
        .client-label { font-weight: bold; }

        .section-title {
            font-size: 10px;
            font-weight: bold;
            color: {{ $cs }};
            margin: 7px 0 4px 0;
        }
        .detail-table th {
            background-color: {{ $cs }};
            color: #ffffff;
            font-weight: bold;
            font-size: 8.6px;
            padding: 7px;
            text-align: left;
        }
        .detail-table th.text-right { text-align: right; }
        .detail-table td {
            padding: 7px;
            border: 0.35px solid #D9E2E7;
            font-size: 8.6px;
            vertical-align: top;
        }
        .row-alt { background-color: #F8FAFB; }
        .line-num { color: #5B6670; font-size: 7.7px; }
        .prod-name { font-weight: bold; color: {{ $cs }}; }
        .prod-desc { color: #5B6670; }
        .text-right { text-align: right; }

        .total-bar { background-color: {{ $cs }}; width: 100%; margin-top: 0; }
        .total-bar td { padding: 10px; color: #ffffff; vertical-align: middle; }
        .total-label { font-size: 11px; font-weight: bold; }
        .total-amount {
            font-size: 17px;
            font-weight: bold;
            text-align: right;
            border-left: 1.5px solid {{ $cp }};
            padding-left: 12px;
        }

        .cond-table td {
            border: 0.5px solid #D9E2E7;
            padding: 7px 8px;
            font-size: 8.5px;
            vertical-align: top;
        }
        .cond-label {
            background-color: {{ $lightAccent }};
            font-weight: bold;
            width: 28mm;
        }

        .observations {
            font-size: 8.5px;
            color: #5B6670;
            margin-top: 8px;
        }

        .footer-line {
            border: none;
            border-top: 1px solid {{ $cp }};
            margin: 12px 0 6px 0;
        }
        .footer-table td {
            font-size: 8.5px;
            color: #5B6670;
            vertical-align: top;
        }
    </style>
</head>
<body>

    {{-- 1. HEADER --}}
    <table class="header-table">
        <tr>
            <td style="width: 50%;">
                @if ($logoBase64)
                    <img src="{{ $logoBase64 }}" class="logo" alt="Logo">
                @endif
            </td>
            <td style="width: 50%; text-align: right;">
                <p class="doc-title">COTIZACIÓN</p>
                <p class="doc-meta">Fecha: {{ $fechaFormateada }}</p>
                <p class="doc-meta">No. de cotización: {{ $cotizacion->numero }}</p>
            </td>
        </tr>
    </table>
    <hr class="header-line">

    {{-- 2. CLIENT BLOCK --}}
    @if ($cotizacion->cliente)
        <div class="client-block">
            <table>
                <tr>
                    <td style="width: 60%;">
                        <span class="client-label">Preparado para:</span> {{ $cotizacion->cliente->nombre }}
                    </td>
                    <td style="width: 40%; text-align: right;">
                        <span class="client-label">Moneda:</span> Pesos dominicanos (RD$)
                    </td>
                </tr>
            </table>
        </div>
    @endif

    {{-- 3. DETAIL TABLE --}}
    <p class="section-title">Detalle de la cotización</p>
    <table class="detail-table">
        <thead>
            <tr>
                <th style="width: 12mm;">#</th>
                <th>Descripción</th>
                <th class="text-right" style="width: 42mm;">Precio</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cotizacion->detalles as $detalle)
                <tr class="{{ $loop->odd ? '' : 'row-alt' }}">
                    <td class="line-num">{{ $loop->iteration }}</td>
                    <td>
                        <span class="prod-name">{{ $detalle->producto->nombre }}</span>
                        @if ($detalle->producto->descripcion)
                            <br><span class="prod-desc">{{ $detalle->producto->descripcion }}</span>
                        @endif
                    </td>
                    <td class="text-right" style="font-weight: bold;">
                        RD${{ number_format((float) $detalle->subtotal, 2, '.', ',') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- 4. TOTAL BAR --}}
    <table class="total-bar">
        <tr>
            <td class="total-label">TOTAL DE LA COTIZACIÓN</td>
            <td class="total-amount" style="width: 40%;">RD${{ number_format((float) $cotizacion->total, 2, '.', ',') }}</td>
        </tr>
    </table>

    {{-- 5. CONDITIONS --}}
    <p class="section-title">Alcance y condiciones del servicio</p>
    <table class="cond-table">
        <tr>
            <td class="cond-label">Incluye</td>
            <td>{{ $incluyeDefault }}</td>
        </tr>
        <tr>
            <td class="cond-label">No incluye</td>
            <td>{{ $noIncluyeDefault }}</td>
        </tr>
    </table>

    {{-- 6. OBSERVATIONS --}}
    <p class="observations">
        <strong>Observaciones:</strong> {{ $observaciones }}
    </p>

    {{-- 7. FOOTER --}}
    <hr class="footer-line">
    <table class="footer-table">
        <tr>
            <td style="width: 60%;">
                <strong>{{ $nombreEmpresa }}</strong>
                @if ($empresa->telefono)
                    · Tel: {{ $empresa->telefono }}
                @endif
                @if ($empresa->email)
                    · {{ $empresa->email }}
                @endif
            </td>
            <td style="width: 40%; text-align: right; color: {{ $cs }};">
                Gracias por considerar nuestros servicios.
            </td>
        </tr>
    </table>

</body>
</html>
