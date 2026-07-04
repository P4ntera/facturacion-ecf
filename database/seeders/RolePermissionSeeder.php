<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permisos = [
            'gestionar_usuarios',
            'gestionar_maestros',
            'gestionar_inventario',
            'registrar_ventas',
            'anular_ventas',
            'gestionar_compras',
            'ver_reportes',
            'administrar_secuencias',
            'ver_auditoria',
        ];

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $administrador = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $administrador->syncPermissions($permisos);

        $vendedor = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $vendedor->syncPermissions([
            'registrar_ventas',
            'gestionar_maestros',
            'ver_reportes',
        ]);

        $almacenista = Role::firstOrCreate(['name' => 'Almacenista', 'guard_name' => 'web']);
        $almacenista->syncPermissions([
            'gestionar_inventario',
            'gestionar_compras',
            'gestionar_maestros',
        ]);

        $admin = User::where('email', 'admin@erp.local')->first();
        $admin?->assignRole('Administrador');
    }
}
