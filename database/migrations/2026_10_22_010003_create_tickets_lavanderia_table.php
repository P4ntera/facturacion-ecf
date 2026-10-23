<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_lavanderia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->foreignId('recepcionista_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('venta_id')->nullable()->constrained()->nullOnDelete();
            $table->string('numero'); // TK-XXXX
            $table->string('estado')->default('recibido');
            $table->string('estado_pago')->default('pendiente');
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('itbis', 15, 2)->default(0);
            $table->text('observaciones')->nullable();
            $table->date('fecha_prometida')->nullable();
            $table->timestamp('entregado_en')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
            $table->index(['empresa_id', 'estado_pago']);
            $table->index(['empresa_id', 'fecha_prometida']);
            $table->unique(['empresa_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_lavanderia');
    }
};
