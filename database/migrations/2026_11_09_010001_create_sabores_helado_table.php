<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sabores_helado', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->string('nombre');
            $table->string('color_hex', 7)->default('#A0522D');
            $table->string('categoria'); // CategoriaSabor enum
            $table->boolean('premium')->default(false);
            $table->boolean('disponible')->default(true);
            $table->boolean('activo')->default(true);
            $table->smallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'activo', 'disponible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sabores_helado');
    }
};
