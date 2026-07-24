<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\ActivityPolicy;
use Filament\Events\TenantSet;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Activity vive en el namespace del paquete spatie/activitylog, no en App\Models: la
        // convención de autodescubrimiento de Policies de Laravel (que solo mapea dentro del
        // propio namespace del modelo) nunca encuentra ActivityPolicy para él. Sin este registro
        // explícito, Filament no localiza ninguna policy y —al no estar en modo estricto— abre
        // el acceso a AuditoriaResource por defecto en vez de negarlo.
        Gate::policy(Activity::class, ActivityPolicy::class);

        // Filament dispara este evento cada vez que fija el tenant activo: en cada request
        // dentro del panel (vía IdentifyTenant) y también cuando el super-admin cambia de
        // empresa con el switcher. En ambos casos hay que fijar el contexto de permisos a la
        // empresa que se está viendo AHORA (el middleware EstablecerEmpresaPermisos solo pone la
        // empresa propia del usuario como punto de partida, antes de que el tenant real esté
        // resuelto — y para el super-admin, que no tiene empresa propia, este evento es la única
        // fuente real de contexto) y limpiar las relaciones roles/permissions ya cargadas en
        // memoria: si no, un cambio de empresa seguiría viendo los roles/permisos de la anterior
        // hasta la siguiente request.
        Event::listen(function (TenantSet $event): void {
            setPermissionsTeamId($event->getTenant()->getKey());

            $usuario = $event->getUser();

            if (method_exists($usuario, 'unsetRelation')) {
                $usuario->unsetRelation('roles')->unsetRelation('permissions');
            }
        });

        // El super-admin no pertenece a ninguna empresa, así que no tiene (ni puede tener) un rol
        // propio con 'teams' => true: en vez de forzarlo a un rol por empresa, se le concede todo
        // de forma transversal aquí. No ensucia los roles de cada empresa y es el único punto que
        // hay que tocar si algún día cambia cómo se identifica al super-admin.
        Gate::before(fn (User $user, string $ability) => $user->es_super_admin ? true : null);
    }
}
