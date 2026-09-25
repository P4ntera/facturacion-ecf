<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_presentaciones', function (Blueprint $table) {
            $table->dropUnique(['codigo_barra']);
            // codigo_barra es nullable: PostgreSQL permite múltiples NULL en un unique compuesto.
            $table->unique(['empresa_id', 'codigo_barra']);
        });
    }

    public function down(): void
    {
        Schema::table('producto_presentaciones', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'codigo_barra']);
            $table->unique('codigo_barra');
        });
    }
};
