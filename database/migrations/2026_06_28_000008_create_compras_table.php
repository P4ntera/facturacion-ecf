<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('tipo_comprobante')->default('41');
            $table->string('ncf')->nullable();
            $table->timestamp('fecha');
            $table->decimal('subtotal', 14, 2)->default(0);
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
        Schema::dropIfExists('compras');
    }
};
