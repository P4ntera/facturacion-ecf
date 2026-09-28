<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->string('nombre', 50);
            $table->string('codigo', 20)->nullable();
            // Token de la URL pública del display del cliente (/display/{token}): no lleva login,
            // así que es la única credencial — 64 caracteres aleatorios, regenerable desde
            // CajaResource si se filtra.
            $table->string('display_token', 64)->unique();
            $table->string('mensaje_display', 120)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            // Postgres permite varios NULL en un unique: el código es opcional.
            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas');
    }
};
