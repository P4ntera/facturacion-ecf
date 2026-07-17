<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->restrictOnDelete();
            $table->boolean('es_principal')->default(false);
            $table->decimal('costo_referencia', 14, 2)->nullable();
            $table->string('codigo_proveedor')->nullable();
            $table->timestamps();

            $table->unique(['producto_id', 'proveedor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_proveedor');
    }
};
