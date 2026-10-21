<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Empresa;
use App\Services\RolesEmpresaService;
use App\Support\Permisos;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Los PERMISOS son globales (catálogo único del sistema): se crean aquí una sola vez, sin
 * team_id. Los ROLES son por empresa (ver App\Services\RolesEmpresaService): en una instalación
 * nueva este seeder corre ANTES de que exista ninguna empresa, así que el paso de abajo no hace
 * nada — EmpresaResource siembra los roles de cada empresa al crearla. Se deja igual por si se
 * reseedea con empresas ya existentes (por ejemplo, en tests), para que ninguna quede sin sus
 * roles base.
 */
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

        // gestionar_arqueo_caja ya está en Permisos::catalogo() (Ventas) desde que se cerró
        // el hueco conocido — lo crea el foreach de arriba, no necesita línea especial.

        // Los permisos gruesos ya no se asignan a ningún rol: se destruyen directamente (no solo
        // se desasignan) para que no quede un registro "vivo" que alguien reactive por error.
        Permission::whereIn('name', self::PERMISOS_GRUESOS_OBSOLETOS)->get()->each->delete();

        Empresa::all()->each(
            fn (Empresa $empresa) => app(RolesEmpresaService::class)->sembrarRolesBase($empresa)
        );
    }
}
