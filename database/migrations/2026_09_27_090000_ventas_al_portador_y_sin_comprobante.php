<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas al portador (cliente_id null) y ventas sin comprobante fiscal (tipo_comprobante y ncf
 * null). Estas últimas son opt-in por empresa (permite_ventas_sin_comprobante, apagado por
 * defecto): la norma DGII exige NCF en toda venta de un contribuyente de ITBIS, así que solo
 * debe activarlo quien no está obligado a emitirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('cliente_id')->nullable()->change();
            $table->string('tipo_comprobante')->nullable()->default('32')->change();
        });

        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->boolean('permite_ventas_sin_comprobante')->default(false)->after('tipo_comprobante_defecto');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->dropColumn('permite_ventas_sin_comprobante');
        });

        // Falla si ya hay ventas al portador o sin comprobante: a propósito, no se inventa un
        // cliente ni un tipo para "rellenarlas".
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('cliente_id')->nullable(false)->change();
            $table->string('tipo_comprobante')->nullable(false)->default('32')->change();
        });
    }
};
