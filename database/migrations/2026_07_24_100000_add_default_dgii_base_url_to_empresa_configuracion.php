<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Antes vivía en la migración de spatie-settings (empresa.dgii_base_url), con el mismo
     * default de sandbox: cada empresa nueva necesita algo con qué arrancar el formulario, ya
     * que el campo es requerido en la UI de configuración fiscal.
     */
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('dgii_base_url')->default('https://sandbox.pac-ecf.example.do/api/v1')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('dgii_base_url')->nullable()->default(null)->change();
        });
    }
};
