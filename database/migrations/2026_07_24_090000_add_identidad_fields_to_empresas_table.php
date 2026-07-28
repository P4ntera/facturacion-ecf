<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de identidad/contacto que antes vivían en EmpresaSettings (spatie-settings, global):
     * al pasar a multi-tenant, cada empresa necesita los suyos propios. rnc/razon_social/
     * nombre_comercial ya existían en esta tabla desde el T1; solo faltaban estos.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('direccion')->nullable()->after('nombre_comercial');
            $table->string('telefono')->nullable()->after('direccion');
            $table->string('email')->nullable()->after('telefono');
            // Igual que EmpresaSettings.logo: ruta relativa dentro del disco público (logos/...),
            // no la URL completa.
            $table->string('logo')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['direccion', 'telefono', 'email', 'logo']);
        });
    }
};
