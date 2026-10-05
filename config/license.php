<?php

return [

    /*
     * Interruptor global: si es false, toda la validación de licencia se omite y el sistema
     * funciona como si todas las empresas tuvieran licencia válida. Útil en desarrollo o si
     * todavía no se ha contratado el servicio de licencias.
     */
    'enabled' => env('LICENSE_ENABLED', false),

    /*
     * URL de la Edge Function de Supabase que valida licencias (POST).
     */
    'api_url' => env('LICENSE_API_URL'),

    /*
     * Anon key del proyecto Supabase (se envía en el header Authorization: Bearer).
     */
    'anon_key' => env('LICENSE_ANON_KEY'),

    /*
     * Clave pública RS256 (formato SPKI/PEM) para verificar la firma del JWT devuelto.
     * La privada vive exclusivamente en Supabase; el cliente solo necesita la pública.
     */
    'public_key' => env('LICENSE_PUBLIC_KEY'),

    /*
     * Nombre del producto registrado en el sistema de licencias. Debe coincidir con el campo
     * `product` de la tabla `licenses` en Supabase.
     */
    'product' => env('LICENSE_PRODUCT', 'facturacion-ecf'),

];
