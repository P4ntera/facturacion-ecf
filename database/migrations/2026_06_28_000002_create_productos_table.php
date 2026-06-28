<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre')->index();
            $table->text('descripcion')->nullable();
            $table->string('tipo')->default('producto');
            $table->foreignId('categoria_id')
                ->nullable()
                ->constrained('categorias')
                ->nullOnDelete();
            $table->decimal('costo', 14, 2)->default(0);
            $table->decimal('precio', 14, 2)->default(0);
            $table->string('tasa_itbis')->default('18');
            $table->boolean('controla_stock')->default(true);
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('stock_minimo', 14, 3)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
