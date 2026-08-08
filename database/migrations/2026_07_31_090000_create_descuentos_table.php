<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('descuentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->string('nombre');
            $table->decimal('porcentaje', 5, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('descuentos');
    }
};
