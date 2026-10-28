<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sesión expirada</title>
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
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            padding: 48px 40px;
            max-width: 420px;
            text-align: center;
        }
        .icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            background: #fef3c7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }
        h1 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        p {
            color: #6b7280;
            font-size: 15px;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        a {
            display: inline-block;
            background: #4f46e5;
            color: #fff;
            text-decoration: none;
            padding: 10px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: background .15s;
        }
        a:hover { background: #4338ca; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">⏰</div>
        <h1>Sesión expirada</h1>
        <p>Tu sesión ha expirado por inactividad. Por favor, recarga la página para continuar trabajando.</p>
        <a href="{{ url()->current() }}">Recargar página</a>
    </div>
</body>
</html>
