<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promociones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->string('nombre');
            $table->string('tipo'); // TipoPromocion enum value
            $table->decimal('valor', 10, 2)->nullable(); // % o monto fijo; null para 2x1
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['empresa_id', 'activa', 'fecha_inicio', 'fecha_fin'], 'promo_vigencia_idx');
        });

        Schema::create('promocion_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocion_id')
                ->constrained('promociones')
                ->cascadeOnDelete();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['promocion_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocion_producto');
        Schema::dropIfExists('promociones');
    }
};
