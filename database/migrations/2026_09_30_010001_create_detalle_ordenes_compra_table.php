<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_compra_id')
                ->constrained('ordenes_compra')
                ->cascadeOnDelete();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();
            $table->decimal('cantidad_solicitada', 12, 4);
            $table->decimal('cantidad_recibida', 12, 4)->default(0);
            $table->decimal('precio_unitario', 15, 2);
            $table->decimal('itbis', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_ordenes_compra');
    }
};
