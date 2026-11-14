<?php

return [

    /*
     * Contraseña inicial de los super-admins que crea FilamentAdminSeeder. En producción es
     * OBLIGATORIA (mínimo 12 caracteres): el seeder se niega a correr sin ella, para que ninguna
     * instalación real nazca con una contraseña conocida. Fuera de producción, si está vacía se
     * usa la contraseña demo. Solo se aplica al CREAR el usuario: nunca pisa una ya cambiada.
     */
    'super_admin_password' => env('SUPER_ADMIN_PASSWORD'),

    /*
     * Versión mínima de PostgreSQL. La búsqueda sin acentos (collation no determinística con
     * LIKE) solo funciona desde la 18; con una anterior fallan el buscador del POS y los listados.
     */
    'postgres_minimo' => 18,

    /*
     * Registro de lentitud (storage/logs/lento-AAAA-MM-DD.log, resumen con `erp:lentitud`).
     * Se anota toda consulta y toda petición que supere su umbral en milisegundos. 0 = apagado.
     */
    'lento' => [
        'consulta_ms' => (int) env('LENTO_CONSULTA_MS', 500),
        'peticion_ms' => (int) env('LENTO_PETICION_MS', 2000),
    ],

    /*
     * Límites de uso (peticiones por minuto, por usuario o por IP si no hay sesión). Protegen al
     * servidor de un script o de un doble clic compulsivo; están muy por encima del uso normal.
     * - livewire: toda acción del panel y del POS (un cajero rápido no pasa de ~180 por minuto).
     * - pdf: comprobantes, tickets y recibos. - reportes_pdf: reportes, que son los más pesados.
     */
    'limites' => [
        'livewire' => (int) env('LIMITE_LIVEWIRE', 900),
        'pdf' => (int) env('LIMITE_PDF', 30),
        'reportes_pdf' => (int) env('LIMITE_REPORTES_PDF', 10),
    ],

    /*
     * Respaldo automático (`php artisan erp:respaldo`, programado a diario). Pensado para las
     * instalaciones locales, donde no hay nadie más cuidando la base de datos.
     *
     * - directorio: dónde se guardan. Vacío = storage/app/respaldos.
     * - copia_directorio: segunda ubicación (otro disco, USB, carpeta sincronizada con la nube).
     *   Un respaldo que vive solo en el mismo disco que la base no protege de que el disco muera.
     * - pg_dump: ruta al ejecutable si no está en el PATH (típico en Windows, p. ej.
     *   "C:\Program Files\PostgreSQL\18\bin\pg_dump.exe").
     */
    'respaldo' => [
        'activo' => (bool) env('RESPALDO_ACTIVO', true),
        'hora' => env('RESPALDO_HORA', '12:30'),
        'retencion_dias' => (int) env('RESPALDO_RETENCION_DIAS', 14),
        'directorio' => env('RESPALDO_DIRECTORIO'),
        'copia_directorio' => env('RESPALDO_COPIA_DIRECTORIO'),
        'pg_dump' => env('RESPALDO_PG_DUMP', 'pg_dump'),
    ],

];
