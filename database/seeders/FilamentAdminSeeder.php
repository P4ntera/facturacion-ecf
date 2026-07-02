<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class FilamentAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@erp.local'],
            [
                'name' => 'Administrador',
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info(
            ($user->wasRecentlyCreated ? 'Creado' : 'Ya existía') . ': admin@erp.local /    '
        );
    }
}
