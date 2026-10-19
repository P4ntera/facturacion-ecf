<?php

use App\Support\Permisos;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Sincroniza los permisos del catálogo con la tabla permissions.
 * Garantiza que permisos nuevos (como productos.ver_costo) se creen
 * al migrar, sin depender de correr el seeder manualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Permisos::todos() as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        Permission::firstOrCreate(['name' => 'gestionar_arqueo_caja', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        // No se eliminan permisos al revertir — podrían estar asignados a roles.
    }
};
