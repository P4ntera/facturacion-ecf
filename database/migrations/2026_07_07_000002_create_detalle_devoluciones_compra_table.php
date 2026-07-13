<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_devoluciones_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucion_compra_id')
                ->constrained('devoluciones_compra')
                ->cascadeOnDelete();
            $table->foreignId('detalle_compra_id')
                ->constrained('detalle_compras')
                ->restrictOnDelete();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();
            $table->decimal('cantidad', 14, 3);
            $table->decimal('costo_unitario', 14, 2);
            $table->string('tasa_itbis');
            $table->decimal('itbis_monto', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_devoluciones_compra');
    }
};
