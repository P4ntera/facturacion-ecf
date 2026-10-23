<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precios_lavanderia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('tipo_prenda_id')->constrained('tipos_prenda')->restrictOnDelete();
            $table->foreignId('servicio_lavanderia_id')->constrained('servicios_lavanderia')->restrictOnDelete();
            $table->decimal('precio', 15, 2);
            $table->timestamps();

            $table->unique(['empresa_id', 'tipo_prenda_id', 'servicio_lavanderia_id'], 'precio_lav_unico');
            $table->index(['empresa_id', 'tipo_prenda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precios_lavanderia');
    }
};
