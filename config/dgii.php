<?php

return [

    /*
     * Fuerza FakeGateway fuera de local (p. ej. en staging, para probar el flujo de e-CF sin
     * disparar envíos reales al PAC). En local siempre se usa el fake, sin importar este valor
     * — ver App\Providers\DgiiServiceProvider.
     */
    'fake' => env('DGII_FAKE_GATEWAY', false),

    /*
     * En APP_ENV=local, permite usar EcfPlatformGateway (el real) SOLO para empresas cuyo
     * ambiente sea TesteCF. Si el ambiente es eCF (producción), sigue usando el fake — nunca
     * se envía a producción desde local. Útil para diagnosticar la comunicación con el PAC
     * sin salir del entorno de desarrollo.
     */
    'forzar_real' => env('DGII_FORZAR_GATEWAY_REAL', false),

];
