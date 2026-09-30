<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campo adicional para el Formato 608 (Comprobantes Fiscales Anulados) de la DGII.
 * Solo aplica a comprobantes físicos (tipo B) — los e-CF se anulan vía Nota de Crédito E34,
 * no a través del 608. El código identifica el motivo de anulación según el catálogo DGII (01-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // Código DGII de tipo de anulación para el 608: 01-10.
            // Nullable porque solo se llena al anular ventas con comprobante tipo B.
            $table->string('tipo_anulacion_608', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('tipo_anulacion_608');
        });
    }
};
