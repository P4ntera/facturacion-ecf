<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable a diferencia de las demás tablas de negocio: los endpoints públicos de la
        // DGII (RecepcionEcfController/AprobacionComercialEcfController) todavía resuelven el RNC
        // propio desde EmpresaSettings (config global, no por-tenant hasta T3), así que la
        // resolución a un empresa_id concreto es best-effort por RNC y puede no encontrar match.
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            $table->foreignId('empresa_id')
                ->after('id')
                ->nullable()
                ->constrained('empresas')
                ->nullOnDelete();

            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
        });
    }
};
