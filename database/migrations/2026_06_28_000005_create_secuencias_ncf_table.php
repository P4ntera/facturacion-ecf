<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secuencias_ncf', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_comprobante');
            $table->string('prefijo');
            $table->unsignedBigInteger('secuencia_actual')->default(1);
            $table->unsignedBigInteger('secuencia_hasta')->nullable();
            $table->date('vencimiento')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['tipo_comprobante', 'prefijo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias_ncf');
    }
};
