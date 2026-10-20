<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('modo_impresion_pos', 20)->default('directa')->after('permite_ventas_sin_comprobante');
        });

        Schema::table('cajas', function (Blueprint $table) {
            // null = usa el de la empresa (cascada).
            $table->string('modo_impresion_pos', 20)->nullable()->after('mensaje_display');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->dropColumn('modo_impresion_pos');
        });

        Schema::table('cajas', function (Blueprint $table) {
            $table->dropColumn('modo_impresion_pos');
        });
    }
};
