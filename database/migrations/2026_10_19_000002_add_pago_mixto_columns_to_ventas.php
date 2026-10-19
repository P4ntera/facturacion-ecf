<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // Montos desglosados por forma de pago (para MIXTO y para registro preciso del vuelto).
            // Nullable: solo se llenan cuando forma_pago = MIXTO, o cuando se paga en efectivo
            // y se necesita registrar el vuelto (monto_efectivo > total).
            $table->decimal('monto_efectivo', 14, 2)->nullable()->after('forma_pago');
            $table->decimal('monto_tarjeta', 14, 2)->nullable()->after('monto_efectivo');
            $table->decimal('monto_transferencia', 14, 2)->nullable()->after('monto_tarjeta');
            $table->decimal('vuelto', 14, 2)->nullable()->after('monto_transferencia');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['monto_efectivo', 'monto_tarjeta', 'monto_transferencia', 'vuelto']);
        });
    }
};
