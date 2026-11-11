<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Super-admins, catálogo de permisos y las empresas demo de los tres verticales
     * (lavandería, heladería, veterinaria), cada uno en sus tres escenarios fiscales:
     * informal, tipo B y e-CF. Ver Database\Seeders\Concerns\SiembraEmpresaDemo.
     */
    public function run(): void
    {
        $this->call([
            FilamentAdminSeeder::class,
            RolePermissionSeeder::class,
            LavanderiaDemoSeeder::class,
            HeladeriaSeeder::class,
            VeterinariaDemoSeeder::class,
        ]);
    }
}
