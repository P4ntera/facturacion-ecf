<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->smallInteger('tipo_pago')->default(1)->after('ncf_modifica');
            $table->date('fecha_limite_pago')->nullable()->after('tipo_pago');

            // Respuesta del PAC (ecf_track_id, ecf_respuesta y estado_fiscal ya existen).
            $table->string('pac_id')->nullable()->after('ecf_track_id');
            $table->string('codigo_seguridad')->nullable()->after('pac_id');
            $table->string('dgii_url')->nullable()->after('codigo_seguridad');
            $table->string('xml_url')->nullable()->after('dgii_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_pago', 'fecha_limite_pago',
                'pac_id', 'codigo_seguridad', 'dgii_url', 'xml_url',
            ]);
        });
    }
};
