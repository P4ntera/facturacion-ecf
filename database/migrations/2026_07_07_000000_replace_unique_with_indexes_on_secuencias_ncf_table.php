<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secuencias_ncf', function (Blueprint $table) {
            // La realidad fiscal permite varios rangos sucesivos del mismo tipo/prefijo (solo uno
            // activo a la vez); esa restricción bloqueaba cargar el segundo rango. El control de
            // "no solapamiento" y "un solo activo" ahora es validación de negocio, no del schema.
            $table->dropUnique(['tipo_comprobante', 'prefijo']);

            $table->index(['tipo_comprobante', 'activa']);
            $table->index(['tipo_comprobante', 'secuencia_desde']);
        });
    }

    public function down(): void
    {
        Schema::table('secuencias_ncf', function (Blueprint $table) {
            $table->dropIndex(['tipo_comprobante', 'activa']);
            $table->dropIndex(['tipo_comprobante', 'secuencia_desde']);

            $table->unique(['tipo_comprobante', 'prefijo']);
        });
    }
};
