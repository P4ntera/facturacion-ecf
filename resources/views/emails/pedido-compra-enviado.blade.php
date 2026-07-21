<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pedido de compra #{{ $pedido->id }}</title>
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #111827;">
    <p>Estimado(a) {{ $pedido->proveedor->nombre }},</p>

    <p>
        Adjunto le enviamos el pedido de compra <strong>#{{ $pedido->id }}</strong>,
        con fecha {{ $pedido->fecha->format('d/m/Y') }}, por un total estimado de
        <strong>RD$ {{ number_format((float) $pedido->total, 2) }}</strong>.
    </p>

    @if ($pedido->notas)
        <p><strong>Notas:</strong> {{ $pedido->notas }}</p>
    @endif

    <p>El detalle completo de los productos y cantidades solicitadas está en el PDF adjunto.</p>

    <p>Saludos,<br>{{ $pedido->user?->name }}</p>
</body>
</html>
