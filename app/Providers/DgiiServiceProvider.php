<?php

namespace App\Providers;

use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\EcfPlatformGateway;
use App\Services\Dgii\FakeGateway;
use Illuminate\Support\ServiceProvider;

class DgiiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El PAC real audita/factura cada envío de e-CF: en local siempre se usa el fake (nunca se
        // dispara un envío real sin querer), y config('dgii.fake') permite forzarlo también en
        // otros entornos (p. ej. staging) para probar el flujo completo sin tocar al PAC.
        $this->app->bind(DgiiGatewayInterface::class, function ($app) {
            if ($app->environment('local') || config('dgii.fake')) {
                return $app->make(FakeGateway::class);
            }

            return $app->make(EcfPlatformGateway::class);
        });
    }
}
