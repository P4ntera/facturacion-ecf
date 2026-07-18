<?php

namespace App\Providers;

use App\Policies\ActivityPolicy;
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
    }
}
