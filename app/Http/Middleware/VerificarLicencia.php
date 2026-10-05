<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\LicenseService;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de enforcement de licencia: cuando la licencia de la empresa activa no es válida,
 * bloquea las operaciones de escritura (POST/PUT/PATCH/DELETE) y deja pasar las de lectura
 * (GET/HEAD/OPTIONS), implementando el modo "solo lectura".
 *
 * Las requests de Livewire/Filament son siempre POST (/livewire/update), así que se tratan
 * de forma especial: solo se bloquean las que parecen ser acciones de escritura (crear, editar,
 * guardar), no la mera navegación o carga de tablas.
 *
 * Se registra como middleware persistente en el panel, DESPUÉS de Authenticate y
 * EstablecerEmpresaPermisos.
 */
class VerificarLicencia
{
    public function __construct(
        private readonly LicenseService $licenseService,
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

        // Para requests Livewire/update, solo bloquear acciones que sean de escritura.
        if ($this->isLivewireRequest($request)) {
            if (! $this->isLivewireWriteAction($request)) {
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
        $payload = $request->input('components.0.calls', []);

        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload as $call) {
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
}
