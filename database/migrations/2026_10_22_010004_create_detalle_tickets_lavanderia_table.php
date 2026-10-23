<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_tickets_lavanderia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_lavanderia_id')->constrained('tickets_lavanderia')->cascadeOnDelete();
            $table->foreignId('tipo_prenda_id')->constrained('tipos_prenda')->restrictOnDelete();
            $table->foreignId('servicio_lavanderia_id')->constrained('servicios_lavanderia')->restrictOnDelete();
            $table->integer('cantidad')->default(1);
            $table->decimal('precio_unitario', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index('ticket_lavanderia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_tickets_lavanderia');
    }
};
