<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_recepciones_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_compra_id')
                ->constrained('recepciones_compra')
                ->cascadeOnDelete();
            $table->foreignId('detalle_orden_compra_id')
                ->constrained('detalle_ordenes_compra')
                ->restrictOnDelete();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();
            $table->decimal('cantidad_recibida', 12, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_recepciones_compra');
    }
};
