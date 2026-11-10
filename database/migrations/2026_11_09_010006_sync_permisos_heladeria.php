<?php

use App\Support\Permisos;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Crea los permisos de heladería en la tabla permissions para que estén
 * disponibles al asignar roles, sin depender de correr el seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalogo = Permisos::catalogo();
        $heladeria = $catalogo['Heladería'] ?? [];

        foreach (array_keys($heladeria) as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
    }

    public function down(): void
    {
        // No se eliminan permisos al revertir — podrían estar asignados a roles.
    }
};
