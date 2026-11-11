<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'heladeria.ver_reportes', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        // No se eliminan permisos al revertir.
    }
};
