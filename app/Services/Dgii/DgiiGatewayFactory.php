<?php

declare(strict_types=1);

namespace App\Services\Dgii;

use App\Models\Empresa;
use Illuminate\Contracts\Foundation\Application;

/**
 * Cada empresa tiene su propia api key/ambiente/base url del PAC (EmpresaConfiguracion, T3):
 * DgiiGatewayInterface ya no puede resolverse como un singleton compartido del contenedor (así
 * estaba antes, atado a una sola configuración global). Este factory construye el gateway
 * correcto para UNA empresa concreta — quien necesite hablar con el PAC debe pasar por aquí, no
 * inyectar DgiiGatewayInterface directo.
 */
class DgiiGatewayFactory
{
    public function __construct(private readonly Application $app) {}

    public function make(Empresa $empresa): DgiiGatewayInterface
    {
        // Mismo criterio que antes en DgiiServiceProvider: en local (o forzado por config) nunca
        // se dispara un envío real, sin importar la empresa. Se resuelve vía el contenedor (no
        // "new FakeGateway()" directo) para que los tests que rebinden DgiiGatewayInterface con
        // su propio stub (ver Tests\Support\GatewayStub) lo sigan pudiendo hacer.
        if ($this->app->environment('local') || config('dgii.fake')) {
            return $this->app->make(DgiiGatewayInterface::class);
        }

        return new EcfPlatformGateway($empresa);
    }
}
