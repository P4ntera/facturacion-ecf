{{--
    Plantilla común de las páginas de error (403, 404, 429, 500, 503). Es HTML estático a propósito:
    no usa base de datos, sesión ni assets compilados, porque tiene que poder mostrarse justo cuando
    algo de eso falló.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $titulo }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            padding: 48px 40px;
            max-width: 440px;
            text-align: center;
        }
        .icon {
            width: 64px; height: 64px; margin: 0 auto 20px;
            background: {{ $fondoIcono ?? '#fee2e2' }};
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px;
        }
        h1 { font-size: 22px; font-weight: 600; margin-bottom: 8px; }
        p { color: #4b5563; font-size: 15px; line-height: 1.5; margin-bottom: 24px; }
        .codigo { color: #6b7280; font-size: 12px; margin: 20px 0 0; }
        .acciones { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        a {
            display: inline-block; text-decoration: none;
            padding: 12px 28px; border-radius: 8px; font-size: 15px; font-weight: 500;
            background: #4338ca; color: #fff;
        }
        a.secundario { background: #fff; color: #1f2937; border: 1px solid #9ca3af; }
        a:hover { filter: brightness(.92); }
        a:focus-visible { outline: 3px solid #f59e0b; outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon" aria-hidden="true">{{ $icono }}</div>
        <h1>{{ $titulo }}</h1>
        <p>{{ $mensaje }}</p>
        <div class="acciones">
            @if ($recargar ?? false)
                <a href="{{ url()->current() }}">Intentar de nuevo</a>
            @endif
            <a href="{{ url('/') }}" @class(['secundario' => $recargar ?? false])>Ir al inicio</a>
        </div>
        <p class="codigo">Código {{ $codigo }}</p>
    </main>
</body>
</html>
