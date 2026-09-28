<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? 'Display' }}</title>
    @livewireStyles
</head>
<body style="margin:0;background:#0f172a">
    {{ $slot }}
    @livewireScripts
</body>
</html>
