<?php

return [

    /*
     * Fuerza FakeGateway fuera de local (p. ej. en staging, para probar el flujo de e-CF sin
     * disparar envíos reales al PAC). En local siempre se usa el fake, sin importar este valor
     * — ver App\Providers\DgiiServiceProvider.
     */
    'fake' => env('DGII_FAKE_GATEWAY', false),

];
