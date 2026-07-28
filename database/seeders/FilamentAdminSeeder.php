<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** Super-admin del sistema: sin empresa propia, ve y administra todas las empresas (tenants). */
class FilamentAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@erp.local'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
                'es_super_admin' => true,
            ]
        );

        $this->command->info(
            ($user->wasRecentlyCreated ? 'Creado' : 'Ya existía').': admin@erp.local /    '
        );
    }
}
