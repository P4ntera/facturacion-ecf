<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impresoras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->string('tipo_conexion');
            $table->string('ip')->nullable();
            $table->integer('puerto')->nullable()->default(9100);
            $table->string('ancho_papel')->default('80');
            $table->string('modulo');
            $table->boolean('predeterminada')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['modulo', 'predeterminada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impresoras');
    }
};
