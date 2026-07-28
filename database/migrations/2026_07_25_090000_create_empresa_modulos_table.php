<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla pivote (en vez de un json en empresa_configuracion) para poder consultar/filtrar
     * módulos deshabilitados directamente por columna (p. ej. "qué empresas tienen apagado
     * COMPRAS"), cosa que un array json no permite sin funciones de JSON en cada consulta.
     */
    public function up(): void
    {
        Schema::create('empresa_modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('modulo');
            $table->boolean('habilitado')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'modulo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_modulos');
    }
};
