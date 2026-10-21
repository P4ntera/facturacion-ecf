<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comanda_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comanda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained()->restrictOnDelete();
            $table->decimal('cantidad', 10, 3)->default(1);
            $table->decimal('precio_unitario', 15, 2);
            $table->text('notas')->nullable();
            $table->string('estado_preparacion')->default('pendiente');
            $table->timestamp('enviado_cocina_en')->nullable();
            $table->timestamp('preparado_en')->nullable();
            $table->timestamps();

            $table->index(['comanda_id', 'estado_preparacion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comanda_detalles');
    }
};
