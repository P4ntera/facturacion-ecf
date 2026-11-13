<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso nuevo: escribir un precio a mano en el ticket de lavandería. Antes cualquiera que
 * creara tickets podía hacerlo. Se entrega a los roles Administrador ya existentes (que por
 * definición tienen todos los permisos) para que una instalación en uso no lo pierda al
 * actualizar; a los demás roles lo asigna el administrador si quiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permiso = Permission::firstOrCreate(['name' => 'lavanderia.precio_manual', 'guard_name' => 'web']);

        Role::query()->where('name', 'Administrador')->get()
            ->each(fn (Role $rol) => $rol->givePermissionTo($permiso));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No se eliminan permisos al revertir — podrían estar asignados a roles.
    }
};
