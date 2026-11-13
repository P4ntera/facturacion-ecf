<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\ManageEmpresa;
use App\Services\LicenseService;
use App\Support\LicenciaSoloLectura;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Livewire\Mechanisms\ComponentRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de enforcement de licencia: cuando la licencia de la empresa activa no es válida,
 * bloquea las operaciones de escritura (POST/PUT/PATCH/DELETE) y deja pasar las de lectura
 * (GET/HEAD/OPTIONS), implementando el modo "solo lectura".
 *
 * Las requests de Livewire/Filament son siempre POST (/livewire/update), así que se tratan
 * de forma especial, en dos capas:
 *
 * 1. Las que claramente son de escritura (crear, guardar, eliminar, ejecutar una acción) se cortan
 *    aquí mismo con un 403 y una notificación.
 * 2. El resto (navegar, paginar, filtrar... y también métodos propios como `cobrar`) se deja pasar
 *    con el candado `LicenciaSoloLectura` activado: si termina intentando escribir una tabla de
 *    negocio, la base de datos lo rechaza. Así el bloqueo no depende del nombre del método.
 *
 * Excepción: la página "Datos de la empresa" siempre puede guardar, porque es donde se registra
 * la clave de licencia (si no, una empresa sin licencia no tendría cómo activarla).
 *
 * Se registra como middleware persistente en el panel, DESPUÉS de Authenticate y
 * EstablecerEmpresaPermisos.
 */
class VerificarLicencia
{
    public function __construct(
        private readonly LicenseService $licenseService,
        private readonly LicenciaSoloLectura $soloLectura,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Si la validación de licencia está desactivada, dejar pasar todo.
        if (! $this->licenseService->isEnabled()) {
            return $next($request);
        }

        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return $next($request);
        }

        $state = $this->licenseService->stateForEmpresa($tenant);

        if ($state['valid']) {
            return $next($request);
        }

        // Licencia no válida: modo solo lectura.
        // Permitir GET, HEAD, OPTIONS sin restricción.
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if ($this->isLivewireRequest($request)) {
            // Registrar/cambiar la clave de licencia tiene que seguir siendo posible.
            if ($this->esPantallaDeLicencia($request)) {
                return $next($request);
            }

            // Lo que no parece escritura pasa, pero con el candado de base de datos puesto.
            if (! $this->isLivewireWriteAction($request)) {
                $this->soloLectura->activar();

                return $next($request);
            }
        }

        // Bloquear la operación de escritura.
        if ($request->expectsJson() || $this->isLivewireRequest($request)) {
            Notification::make()
                ->title('Licencia no válida')
                ->body('El sistema está en modo solo lectura. Contacte al administrador para renovar la licencia.')
                ->danger()
                ->persistent()
                ->send();

            // Devolver la respuesta de abort con un 403 no funciona bien con Livewire;
            // devolver la respuesta del siguiente middleware permite que Filament muestre la
            // notificación pero no procese la acción.
            abort(403, 'Licencia no válida. El sistema está en modo solo lectura.');
        }

        abort(403, 'Licencia no válida. El sistema está en modo solo lectura.');
    }

    private function isLivewireRequest(Request $request): bool
    {
        return str_contains($request->path(), 'livewire/update')
            || $request->hasHeader('X-Livewire');
    }

    /**
     * Determina si una request de Livewire parece ser una acción de escritura.
     * Las acciones de navegación, paginación y carga de datos se dejan pasar.
     */
    private function isLivewireWriteAction(Request $request): bool
    {
        foreach ($this->llamadasLivewire($request) as $call) {
            $method = $call['method'] ?? '';

            // Métodos que indican escritura (crear, guardar, eliminar, enviar form).
            if (in_array($method, ['create', 'save', 'delete', 'mount', 'callMountedAction', 'callMountedFormComponentAction'])) {
                return true;
            }

            // submit de formularios
            if (str_starts_with($method, 'save') || str_starts_with($method, 'submit')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Todas las llamadas a métodos de TODOS los componentes de la request (una request de
     * Livewire puede traer varios componentes; mirar solo el primero dejaba pasar el resto).
     *
     * @return list<array<string, mixed>>
     */
    private function llamadasLivewire(Request $request): array
    {
        $llamadas = [];

        foreach ((array) $request->input('components', []) as $componente) {
            foreach ((array) ($componente['calls'] ?? []) as $llamada) {
                if (is_array($llamada)) {
                    $llamadas[] = $llamada;
                }
            }
        }

        return $llamadas;
    }

    /**
     * true si TODOS los componentes de la request son la página "Datos de la empresa". El nombre
     * sale del snapshot, que Livewire firma con checksum: no se puede falsificar para colar otro
     * componente.
     */
    private function esPantallaDeLicencia(Request $request): bool
    {
        $componentes = (array) $request->input('components', []);

        if ($componentes === []) {
            return false;
        }

        foreach ($componentes as $componente) {
            $snapshot = json_decode((string) ($componente['snapshot'] ?? ''), true);
            $nombre = $snapshot['memo']['name'] ?? null;

            if (! is_string($nombre)) {
                return false;
            }

            try {
                $clase = app(ComponentRegistry::class)->getClass($nombre);
            } catch (\Throwable) {
                return false;
            }

            if ($clase !== ManageEmpresa::class) {
                return false;
            }
        }

        return true;
    }
}
