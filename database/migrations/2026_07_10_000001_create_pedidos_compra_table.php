<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('fecha');
            $table->string('estado')->default('pendiente');
            $table->text('notas')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('monto_gravado_18', 14, 2)->default(0);
            $table->decimal('monto_gravado_16', 14, 2)->default(0);
            $table->decimal('monto_gravado_0', 14, 2)->default(0);
            $table->decimal('monto_exento', 14, 2)->default(0);
            $table->decimal('itbis_18', 14, 2)->default(0);
            $table->decimal('itbis_16', 14, 2)->default(0);
            $table->decimal('itbis', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('enviado_en')->nullable();
            $table->string('enviado_a')->nullable();
            $table->string('motivo_cancelacion')->nullable();
            $table->timestamp('cancelado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_compra');
    }
};
