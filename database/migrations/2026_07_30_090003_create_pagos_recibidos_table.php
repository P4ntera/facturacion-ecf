<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_recibidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->foreignId('cuenta_por_cobrar_id')
                ->constrained('cuentas_por_cobrar')
                ->restrictOnDelete();
            $table->decimal('monto', 14, 2);
            $table->date('fecha');
            $table->string('forma_pago');
            $table->string('referencia')->nullable();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('estado')->default('registrado');
            $table->string('motivo_anulacion')->nullable();
            $table->timestamp('anulado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_recibidos');
    }
};
