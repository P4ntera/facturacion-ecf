<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function setPermissionsTeamId;

/**
 * spatie/laravel-permission con 'teams' => true exige un "team" (empresa_id) activo para
 * cualquier consulta de roles/permisos: sin esto, getAllPermissions()/hasRole() etc. no
 * encuentran nada (ven todo vacío), incluida la propia comprobación canAccessPanel() del login.
 *
 * Se registra en el array ->middleware() del panel, ANTES de SubstituteBindings (ver
 * AdminPanelProvider): en ese punto Filament::getTenant() todavía no está resuelto (lo resuelve
 * IdentifyTenant más adelante en el pipeline, dentro del grupo de rutas del tenant), así que aquí
 * se usa la empresa del propio usuario como valor de partida — suficiente para que
 * canAccessPanel() y el resto de checks tempranos ya tengan contexto. En cuanto IdentifyTenant
 * resuelve el tenant real (incluido el caso del super-admin cambiando de empresa con el
 * switcher), el listener de Filament\Events\TenantSet (ver AppServiceProvider) vuelve a fijar el
 * contexto con el tenant definitivo.
 */
class EstablecerEmpresaPermisos
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null) {
            setPermissionsTeamId(Filament::getTenant()?->id ?? $usuario->empresa_id);
        }

        return $next($request);
    }
}
