<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Prueba de impresión — {{ $impresora->nombre }}</title>
    @include('impresoras._estilos-ticket', ['anchoPapel' => $impresora->ancho_papel])
</head>
<body onload="window.print()">
    <div class="hoja">
        <p class="centro negrita">PRUEBA DE IMPRESIÓN</p>
        <div class="separador"></div>
        <p>Impresora: {{ $impresora->nombre }}</p>
        <p>Tipo: {{ $impresora->tipo_conexion->etiqueta() }}</p>
        <p>Ancho: {{ $impresora->ancho_papel->etiqueta() }} ({{ $impresora->ancho_papel->columnas() }} columnas)</p>
        <p>Fecha: {{ now()->format('d/m/Y H:i') }}</p>
        <div class="separador"></div>
        <p class="centro">
            Si puedes leer este texto completo<br>
            dentro del ancho del papel, la<br>
            configuración de esta impresora es correcta.
        </p>
    </div>
</body>
</html>
