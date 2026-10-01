<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->foreignId('cliente_id')
                ->nullable()
                ->constrained('clientes')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('numero');
            $table->date('fecha');
            $table->date('fecha_vencimiento');
            $table->integer('dias_vigencia')->default(15);
            $table->string('condiciones_pago')->nullable();
            $table->text('notas')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('descuento', 15, 2)->default(0);
            $table->decimal('itbis', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('estado')->default('borrador');
            $table->foreignId('venta_id')
                ->nullable()
                ->constrained('ventas')
                ->nullOnDelete();
            $table->foreignId('aprobado_por')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('aprobado_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'numero']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
