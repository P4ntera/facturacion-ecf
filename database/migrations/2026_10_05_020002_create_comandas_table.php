<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comandas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('mesa_id')->constrained('mesas')->restrictOnDelete();
            $table->foreignId('mesero_id')->constrained('users')->restrictOnDelete();
            $table->string('numero');
            $table->integer('comensales')->default(1);
            $table->string('estado')->default('abierta');
            $table->text('notas')->nullable();
            $table->foreignId('venta_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
            $table->index(['mesa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comandas');
    }
};
