<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lista_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
        });

        Schema::create('lista_precio_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_precio_id')->constrained('lista_precios')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained()->restrictOnDelete();
            $table->decimal('precio', 14, 2);
            $table->timestamps();

            $table->unique(['lista_precio_id', 'producto_id']);
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->foreignId('lista_precio_id')->nullable()->after('activo')
                ->constrained('lista_precios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lista_precio_id');
        });

        Schema::dropIfExists('lista_precio_producto');
        Schema::dropIfExists('lista_precios');
    }
};
