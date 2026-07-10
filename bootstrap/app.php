<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'             => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'       => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // La DGII llama estas dos URLs directamente (sin sesión de nuestro panel, no hay forma de
        // que traigan un token CSRF): la seguridad la da RecepcionEcfService (RNC/tamaño/registro),
        // no la sesión.
        $middleware->validateCsrfTokens(except: [
            'fe/recepcion/api/ecf',
            'fe/aprobacioncomercial/api/ecf',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
