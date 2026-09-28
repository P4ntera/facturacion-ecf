<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos adicionales para el Formato 606 (Envío de Compras de Bienes y Servicios) de la DGII.
 * Estos campos no existían porque el modelo original de compras solo rastreaba la operación
 * comercial, no la metadata fiscal requerida por el 606.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            // Código DGII de tipo de bienes/servicios (catálogo 606): 01-11.
            // Default 09 = "Compras y gastos que formarán parte del costo de venta", que es
            // lo más común para un ERP de comercio.
            $table->string('tipo_bienes_servicios_606', 2)->default('09');

            // Forma de pago para el 606, separada del tipo_pago existente (que es un enum
            // CONTADO/CREDITO). El 606 usa un catálogo DGII propio: 01=Efectivo, 02=Cheque/
            // Transferencia, 03=Tarjeta, 04=Crédito, 05=Permuta, 06=NC, 07=Mixto.
            $table->string('forma_pago_606', 2)->default('01');

            // Fecha de pago real (para compras a crédito que se pagan después).
            $table->date('fecha_pago')->nullable();

            // Retenciones aplicadas por la empresa (si es agente de retención).
            $table->decimal('retencion_itbis', 15, 2)->default(0);
            $table->decimal('retencion_isr', 15, 2)->default(0);
            $table->string('tipo_retencion_isr', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_bienes_servicios_606',
                'forma_pago_606',
                'fecha_pago',
                'retencion_itbis',
                'retencion_isr',
                'tipo_retencion_isr',
            ]);
        });
    }
};
