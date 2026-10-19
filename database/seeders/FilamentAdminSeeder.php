<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Super-admins del sistema: sin empresa propia, ven y administran todas las empresas (tenants).
 * Se siembran dos por seguridad — si uno pierde acceso, el otro puede recuperar el sistema.
 */
class FilamentAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admins = [
            ['email' => 'admin@erp.local',   'name' => 'Super Admin'],
            ['email' => 'admin2@erp.local',  'name' => 'Super Admin 2'],
        ];

        foreach ($admins as $datos) {
            $user = User::firstOrCreate(
                ['email' => $datos['email']],
                [
                    'name' => $datos['name'],
                    'password' => bcrypt('password123'),
                    'email_verified_at' => now(),
                    'es_super_admin' => true,
                ]
            );

            $this->command->info(
                ($user->wasRecentlyCreated ? 'Creado' : 'Ya existía').": {$datos['email']}"
            );
        }
    }
}
