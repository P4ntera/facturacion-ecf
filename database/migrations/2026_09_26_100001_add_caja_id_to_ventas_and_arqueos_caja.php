<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * caja_id es nullable en ambas tablas: las ventas/arqueos históricos (y los de Caja/Facturación,
 * que no piden elegir caja física) no tienen una asociada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('empresa_id')
                ->constrained('cajas')->restrictOnDelete();
        });

        Schema::table('arqueos_caja', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('empresa_id')
                ->constrained('cajas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('arqueos_caja', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_id');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_id');
        });
    }
};
