<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('tipo_comprobante')->default('32');
            $table->string('ncf')->nullable()->unique();
            $table->string('ncf_modifica')->nullable();
            $table->timestamp('fecha');
            $table->string('moneda')->default('DOP');
            $table->decimal('tasa_cambio', 14, 4)->default(1);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('monto_gravado_18', 14, 2)->default(0);
            $table->decimal('monto_gravado_16', 14, 2)->default(0);
            $table->decimal('monto_gravado_0', 14, 2)->default(0);
            $table->decimal('monto_exento', 14, 2)->default(0);
            $table->decimal('itbis_18', 14, 2)->default(0);
            $table->decimal('itbis_16', 14, 2)->default(0);
            $table->decimal('total_itbis', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('estado')->default('emitida');
            $table->string('estado_fiscal')->default('no_aplica');
            $table->string('ecf_track_id')->nullable();
            $table->timestamp('ecf_enviado_en')->nullable();
            $table->json('ecf_respuesta')->nullable();
            $table->string('motivo_anulacion')->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->timestamps();

            $table->index(['fecha', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
