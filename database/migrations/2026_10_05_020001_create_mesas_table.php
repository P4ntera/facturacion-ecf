<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('area_restaurante_id')->constrained('areas_restaurante')->restrictOnDelete();
            $table->string('numero');
            $table->integer('capacidad')->default(4);
            $table->string('estado')->default('disponible');
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesas');
    }
};
