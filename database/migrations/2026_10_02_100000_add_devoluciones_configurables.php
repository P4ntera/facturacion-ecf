<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 3 — devoluciones de clientes configurables por empresa.
 *
 * - empresa_configuracion: si acepta devoluciones, plazo en días (null = sin límite), formas de
 *   reembolso que acepta (null = todas) y monto a partir del cual hace falta un supervisor
 *   (null = nunca).
 * - ventas (la nota de crédito de la devolución es una fila más de ventas): cómo se reembolsó,
 *   cuánto se reembolsó y cuánto se rebajó de la cuenta por cobrar si la venta fue a crédito.
 * - detalle_ventas.destino_devolucion: cada producto devuelto vuelve al inventario o va a merma.
 * - arqueos_caja.total_devoluciones_efectivo: el efectivo que salió de la caja por devoluciones,
 *   que se resta del efectivo esperado al cerrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->boolean('acepta_devoluciones')->default(true);
            $table->unsignedSmallInteger('devolucion_plazo_dias')->nullable();
            $table->json('devolucion_reembolsos')->nullable();
            $table->decimal('devolucion_monto_supervisor', 14, 2)->nullable();
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->string('forma_reembolso', 20)->nullable();
            $table->decimal('monto_reembolso', 14, 2)->nullable();
            $table->decimal('monto_rebaja_cxc', 14, 2)->nullable();
        });

        Schema::table('detalle_ventas', function (Blueprint $table) {
            $table->string('destino_devolucion', 20)->nullable();
        });

        Schema::table('arqueos_caja', function (Blueprint $table) {
            $table->decimal('total_devoluciones_efectivo', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('arqueos_caja', fn (Blueprint $table) => $table->dropColumn('total_devoluciones_efectivo'));
        Schema::table('detalle_ventas', fn (Blueprint $table) => $table->dropColumn('destino_devolucion'));
        Schema::table('ventas', fn (Blueprint $table) => $table->dropColumn(['forma_reembolso', 'monto_reembolso', 'monto_rebaja_cxc']));
        Schema::table('empresa_configuracion', fn (Blueprint $table) => $table->dropColumn([
            'acepta_devoluciones', 'devolucion_plazo_dias', 'devolucion_reembolsos', 'devolucion_monto_supervisor',
        ]));
    }
};
