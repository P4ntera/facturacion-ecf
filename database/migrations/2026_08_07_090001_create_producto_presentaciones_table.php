<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_presentaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnDelete();
            $table->string('nombre');
            $table->decimal('factor', 14, 3);
            // Postgres permite múltiples NULL en un índice único: presentaciones sin código de
            // barras propio no chocan entre sí (mismo criterio que productos.codigo_barra).
            $table->string('codigo_barra')->nullable()->unique();
            $table->decimal('precio', 14, 2);
            $table->boolean('es_base')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_presentaciones');
    }
};
