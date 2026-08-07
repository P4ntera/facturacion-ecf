<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_ventas', function (Blueprint $table) {
            // nullOnDelete (no restrict): la presentación puede desactivarse o borrarse más
            // adelante sin que eso bloquee ni altere el historial de ventas ya facturadas.
            $table->foreignId('presentacion_id')
                ->nullable()
                ->after('producto_id')
                ->constrained('producto_presentaciones')
                ->nullOnDelete();

            $table->decimal('factor', 14, 3)->default(1)->after('cantidad');
        });
    }

    public function down(): void
    {
        Schema::table('detalle_ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('presentacion_id');
            $table->dropColumn('factor');
        });
    }
};
