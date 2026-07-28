<?php

use App\Http\Middleware\EstablecerEmpresaPermisos;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // La DGII llama estas dos URLs directamente (sin sesión de nuestro panel, no hay forma de
        // que traigan un token CSRF): la seguridad la da RecepcionEcfService (RNC/tamaño/registro),
        // no la sesión.
        $middleware->validateCsrfTokens(except: [
            'fe/recepcion/api/ecf',
            'fe/aprobacioncomercial/api/ecf',
        ]);

        // CRÍTICO: la posición en el array ->middleware() del panel (ver AdminPanelProvider) NO
        // garantiza el orden real de ejecución. Laravel reordena todo middleware "conocido" (con
        // prioridad asignada, como Authenticate/SubstituteBindings) según esta lista de
        // prioridad, sin importar en qué array se registró — confirmado con
        // `artisan route:list --json`, que mostró a Authenticate ejecutándose ANTES de
        // EstablecerEmpresaPermisos pese a estar declarado después en el array. Sin esto,
        // canAccessPanel() (dentro de Authenticate) corre sin contexto de empresa fijado.
        //
        // El "before" tiene que ser la INTERFAZ AuthenticatesRequests, no
        // Illuminate\Auth\Middleware\Authenticate ni menos Filament\Http\Middleware\Authenticate
        // (su subclase): la lista de prioridad por defecto del framework solo trae la interfaz
        // (confirmado inspeccionando Kernel::$middlewarePriority en tinker), y el método que
        // registra el prepend (Kernel::addToMiddlewarePriorityRelative) busca coincidencia EXACTA
        // de string, no por herencia. El SortedMiddleware que sí ordena cada request resuelve
        // subclases/interfaces contra la lista (recorre class_implements()+class_parents()), así
        // que anclar a la interfaz es lo único que funciona para las dos clases concretas.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EstablecerEmpresaPermisos::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
