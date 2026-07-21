<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arqueos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->decimal('fondo_inicial', 14, 2);
            $table->timestamp('abierto_en');
            $table->timestamp('cerrado_en')->nullable();
            $table->string('estado')->default('abierto');
            $table->decimal('total_ventas_efectivo', 14, 2)->nullable();
            $table->decimal('total_ventas_tarjeta', 14, 2)->nullable();
            $table->decimal('total_ventas_transferencia', 14, 2)->nullable();
            $table->decimal('efectivo_esperado', 14, 2)->nullable();
            $table->decimal('efectivo_contado', 14, 2)->nullable();
            $table->decimal('diferencia', 14, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arqueos_caja');
    }
};
