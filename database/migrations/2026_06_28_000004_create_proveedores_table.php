<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
<<<<<<< HEAD
            $table->string('rnc')->nullable()->index();
            $table->string('nombre');
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('direccion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
=======
            $table->string('rnc', 11)->unique()->index();
            $table->string('nombre');
            $table->string('nombre_comercial')->nullable();
            $table->string('actividad_economica')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('direccion')->nullable();
            $table->enum('estado', ['ACTIVO', 'INACTIVO', 'SUSPENDIDO', 'DADO DE BAJA'])->default('ACTIVO');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
>>>>>>> Lamar
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
