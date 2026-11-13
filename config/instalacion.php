<?php

return [

    /*
     * Contraseña inicial de los super-admins que crea FilamentAdminSeeder. En producción es
     * OBLIGATORIA (mínimo 12 caracteres): el seeder se niega a correr sin ella, para que ninguna
     * instalación real nazca con una contraseña conocida. Fuera de producción, si está vacía se
     * usa la contraseña demo. Solo se aplica al CREAR el usuario: nunca pisa una ya cambiada.
     */
    'super_admin_password' => env('SUPER_ADMIN_PASSWORD'),

];
