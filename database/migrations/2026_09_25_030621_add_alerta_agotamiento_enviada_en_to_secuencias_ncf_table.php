<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('secuencias_ncf', function (Blueprint $table) {
            // Null = alerta de "por agotarse" no enviada todavía para el tramo bajo el umbral
            // actual. Se resetea a null si el rango se extiende (secuencia_hasta sube) para que
            // la alerta pueda volver a dispararse si el rango vuelve a acercarse al límite.
            $table->timestamp('alerta_agotamiento_enviada_en')->nullable()->after('activa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('secuencias_ncf', function (Blueprint $table) {
            $table->dropColumn('alerta_agotamiento_enviada_en');
        });
    }
};
