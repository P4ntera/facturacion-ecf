<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota de Crédito de anulación (e-CF 34): se guarda como una fila más de ventas que apunta a la
 * venta que anula. ncf_modifica ya guarda el e-NCF (lo que viaja a la DGII); este FK da la
 * relación sin depender de buscar por texto, y permite al 607 y a los reportes de ingresos
 * reconocer el par original/nota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('venta_modificada_id')->nullable()->after('ncf_modifica')
                ->constrained('ventas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venta_modificada_id');
        });
    }
};
