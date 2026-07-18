<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\Permisos;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo granular por pantalla (app/Support/Permisos.php): se crea aquí de forma
        // idempotente. Los roles todavía se asignan con los permisos gruesos de abajo hasta que
        // las Policies/páginas se migren a los granulares (ver siguiente commit).
        foreach (Permisos::todos() as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $permisos = [
            'gestionar_usuarios',
            'gestionar_maestros',
            'gestionar_inventario',
            'registrar_ventas',
            'anular_ventas',
            'gestionar_arqueo_caja',
            'gestionar_compras',
            'anular_compras',
            'ver_reportes',
            'administrar_secuencias',
            'ver_auditoria',
            'administrar_configuracion',
            'gestionar_ecf',
        ];

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $administrador = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $administrador->syncPermissions($permisos);

        $vendedor = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $vendedor->syncPermissions([
            'registrar_ventas',
            'gestionar_arqueo_caja',
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
