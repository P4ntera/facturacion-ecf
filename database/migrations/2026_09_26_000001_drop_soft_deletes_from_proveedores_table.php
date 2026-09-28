<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El sistema no usa SoftDeletes: los maestros se desactivan con el booleano `activo`. La columna
 * `deleted_at` de proveedores venía de la migración original y nunca la usó el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
