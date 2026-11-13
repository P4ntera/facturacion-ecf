<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Super-admins del sistema: sin empresa propia, ven y administran todas las empresas (tenants).
 * Se siembran dos por seguridad — si uno pierde acceso, el otro puede recuperar el sistema.
 *
 * La contraseña inicial sale de SUPER_ADMIN_PASSWORD (config/instalacion.php). En producción es
 * obligatoria: sin ella el seeder aborta antes de crear nada. La contraseña demo solo existe
 * para desarrollo y tests.
 */
class FilamentAdminSeeder extends Seeder
{
    /** Contraseña de desarrollo/tests. Nunca se usa en producción. */
    public const PASSWORD_DEMO = 'password123';

    public const LONGITUD_MINIMA_PRODUCCION = 12;

    public function run(): void
    {
        $password = $this->passwordInicial();

        $admins = [
            ['email' => 'eugeniososa0925@gmail.com',   'name' => 'Super Admin'],
            ['email' => 'fesrsoft25@gmail.com',  'name' => 'Super Admin 2'],
        ];

        foreach ($admins as $datos) {
            $user = User::firstOrCreate(
                ['email' => $datos['email']],
                [
                    'name' => $datos['name'],
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'es_super_admin' => true,
                ]
            );

            $this->command?->info(
                ($user->wasRecentlyCreated ? 'Creado' : 'Ya existía').": {$datos['email']}"
            );

            // Instalaciones sembradas antes de este cambio: el usuario ya existe y firstOrCreate
            // no le toca la contraseña, así que hay que avisar para que la cambien a mano.
            if (app()->isProduction() && Hash::check(self::PASSWORD_DEMO, $user->password)) {
                $this->command?->error(
                    "ATENCIÓN: {$datos['email']} sigue con la contraseña por defecto. Cámbiela ahora desde el panel."
                );
            }
        }
    }

    private function passwordInicial(): string
    {
        $configurada = (string) config('instalacion.super_admin_password');

        if (! app()->isProduction()) {
            return $configurada !== '' ? $configurada : self::PASSWORD_DEMO;
        }

        if (mb_strlen($configurada) < self::LONGITUD_MINIMA_PRODUCCION || $configurada === self::PASSWORD_DEMO) {
            throw new RuntimeException(
                'Defina SUPER_ADMIN_PASSWORD en el .env (mínimo '.self::LONGITUD_MINIMA_PRODUCCION
                .' caracteres) antes de sembrar los super-admins en producción.'
            );
        }

        return $configurada;
    }
}
