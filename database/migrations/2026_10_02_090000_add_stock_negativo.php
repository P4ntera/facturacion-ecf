<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 2 — vender sin stock:
 * - empresa_configuracion.permite_stock_negativo: interruptor por empresa (apagado por defecto).
 *   Solo las VENTAS pueden dejar el stock en negativo; ajustes, anular compras y devolver al
 *   proveedor siguen bloqueados.
 * - movimientos_inventario.dejo_stock_negativo: marca en el Kardex de cada salida que dejó el
 *   producto por debajo de cero, para saber quién y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->boolean('permite_stock_negativo')->default(false);
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->boolean('dejo_stock_negativo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', fn (Blueprint $table) => $table->dropColumn('dejo_stock_negativo'));
        Schema::table('empresa_configuracion', fn (Blueprint $table) => $table->dropColumn('permite_stock_negativo'));
    }
};
