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
    /**
     * Permisos gruesos que existían antes de la matriz granular (app/Support/Permisos.php).
     * Ya no los usa ninguna Policy/gate del código: se eliminan aquí para no dejar nada
     * colgando en la base de datos (huérfanos que un administrador podría marcar por error
     * pensando que todavía hacen algo).
     */
    private const PERMISOS_GRUESOS_OBSOLETOS = [
        'gestionar_usuarios',
        'gestionar_maestros',
        'gestionar_inventario',
        'registrar_ventas',
        'anular_ventas',
        'gestionar_compras',
        'anular_compras',
        'ver_reportes',
        'administrar_secuencias',
        'ver_auditoria',
        'administrar_configuracion',
        'gestionar_ecf',
    ];

    public function run(): void
    {
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
        $administrador->syncPermissions(Permisos::todos());

        $vendedor = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $vendedor->syncPermissions([
            'registrar_ventas',
            'gestionar_arqueo_caja',
            'gestionar_maestros',
            'ver_reportes',
            'pos.acceder',
            'ventas.ver',
            'ventas.imprimir',
            // "Maestros" del Vendedor se limita a consultar lo que necesita para vender
            // (precios de productos, datos de clientes); crear/editar/desactivar maestros
            // queda fuera de su pantalla de trabajo.
            'productos.ver',
            'clientes.ver',
            // Exportar va junto con ver: un vendedor que puede consultar un reporte también
            // necesita poder sacarlo en PDF/Excel para el día a día (cierre de caja, etc.).
            'reportes.ver',
            'reportes.exportar',
        ]);

        $almacenista = Role::firstOrCreate(['name' => 'Almacenista', 'guard_name' => 'web']);
        $almacenista->syncPermissions([
            'inventario.ajustar',
            'kardex.ver',
            'compras.ver',
            'compras.crear',
            // compras.anular queda fuera a propósito: anular una compra tiene impacto contable/
            // de inventario que se reserva al Administrador (regla ya existente y probada).
            'devoluciones.ver',
            'devoluciones.crear',
            'productos.ver',
            'productos.crear',
            'productos.editar',
            'productos.desactivar',
            // Solo lectura: necesita ver el proveedor al registrar una compra, pero darlo de
            // alta/editarlo es tarea de quien mantiene los maestros, no del almacén.
            'proveedores.ver',
        ]);

        // Los permisos gruesos ya no se asignan a ningún rol: se destruyen directamente (no solo
        // se desasignan) para que no quede un registro "vivo" que alguien reactive por error.
        Permission::whereIn('name', self::PERMISOS_GRUESOS_OBSOLETOS)->get()->each->delete();

        $admin = User::where('email', 'admin@erp.local')->first();
        $admin?->assignRole('Administrador');
    }
}
