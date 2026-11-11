<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // Referencia al descuento global aplicado: cuál se usó y a qué porcentaje estaba en ese
            // momento. Ambos nullable porque no toda venta lleva descuento global, y un descuento
            // puede desactivarse/eliminarse sin invalidar el historial (SET NULL).
            $table->foreignId('descuento_id')
                ->nullable()
                ->after('descuento')
                ->constrained('descuentos')
                ->nullOnDelete();

            $table->decimal('descuento_porcentaje', 5, 2)
                ->nullable()
                ->after('descuento_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['descuento_id']);
            $table->dropColumn(['descuento_id', 'descuento_porcentaje']);
        });
    }
};
