<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 1 de costo y precio:
 * - empresa_configuracion: método de costo y redondeo del precio sugerido, por empresa. Las
 *   empresas existentes quedan en "ultima_compra", que es como el sistema funcionaba hasta ahora.
 * - categorias / productos: porcentaje de ganancia sobre el costo (el del producto, si está,
 *   manda sobre el de su categoría). Nullable = sin precio sugerido.
 * - detalle_ventas: costo del momento de la venta, para que los reportes de ganancia no cambien
 *   cada vez que cambia el costo del producto. Nullable: las ventas anteriores no lo tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('metodo_costo', 30)->default('ultima_compra');
            $table->string('redondeo_precio', 10)->default('ninguno');
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->decimal('margen_ganancia', 7, 2)->nullable();
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('margen_ganancia', 7, 2)->nullable();
        });

        Schema::table('detalle_ventas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('detalle_ventas', fn (Blueprint $table) => $table->dropColumn('costo_unitario'));
        Schema::table('productos', fn (Blueprint $table) => $table->dropColumn('margen_ganancia'));
        Schema::table('categorias', fn (Blueprint $table) => $table->dropColumn('margen_ganancia'));
        Schema::table('empresa_configuracion', fn (Blueprint $table) => $table->dropColumn(['metodo_costo', 'redondeo_precio']));
    }
};
