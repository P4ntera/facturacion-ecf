<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devoluciones_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')
                ->constrained('compras')
                ->restrictOnDelete();
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('fecha');
            $table->string('motivo');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('monto_gravado_18', 14, 2)->default(0);
            $table->decimal('monto_gravado_16', 14, 2)->default(0);
            $table->decimal('monto_gravado_0', 14, 2)->default(0);
            $table->decimal('itbis_18', 14, 2)->default(0);
            $table->decimal('itbis_16', 14, 2)->default(0);
            $table->decimal('itbis', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('estado')->default('registrada');
            $table->string('motivo_anulacion')->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones_compra');
    }
};
