<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function setPermissionsTeamId;

/**
 * spatie/laravel-permission con 'teams' => true exige un "team" (empresa_id) activo para
 * cualquier consulta de roles/permisos: sin esto, getAllPermissions()/hasRole() etc. no
 * encuentran nada (ven todo vacío), incluida la propia comprobación canAccessPanel() del login.
 *
 * Se registra en el array ->middleware() del panel, ANTES de SubstituteBindings (ver
 * AdminPanelProvider) — y también antes de Authenticate, ambos vía la lista de prioridad del
 * framework en bootstrap/app.php: la posición en el array del panel NO basta, Laravel reordena
 * el middleware "conocido" según esa lista sin importar en qué array se registró.
 *
 * Usa siempre la empresa del propio usuario, NUNCA Filament::getTenant(): en este punto del
 * pipeline el tenant activo puede ser el de una request/sesión anterior a este mismo objeto
 * FilamentManager (p. ej. en tests, que llaman Filament::setTenant() en el setUp() para que
 * Livewire::test() pueda generar URLs con {tenant}), no necesariamente el de la request actual
 * (que resuelve IdentifyTenant más adelante en el pipeline). Para un usuario normal esto no
 * pierde nada: solo pertenece a una empresa, así que su contexto siempre es la suya. El
 * super-admin no tiene empresa propia (empresa_id null): su caso lo cubre por completo
 * Gate::before en AppServiceProvider (no depende de tener un team_id fijado), y en cuanto entra
 * a una empresa concreta el listener de Filament\Events\TenantSet (ver AppServiceProvider) fija
 * el contexto real y limpia las relaciones cacheadas — también cubre al super-admin cambiando de
 * empresa con el switcher.
 */
class EstablecerEmpresaPermisos
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null) {
            setPermissionsTeamId($usuario->empresa_id);

            // El objeto User puede traer roles/permissions ya cargados en memoria de un
            // contexto de empresa distinto (p. ej. en tests, que reutilizan la misma instancia
            // entre requests simuladas vía actingAs()): sin esto, getAllPermissions() podría
            // devolver datos de OTRA empresa hasta que algo más los invalide.
            $usuario->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $next($request);
    }
}
