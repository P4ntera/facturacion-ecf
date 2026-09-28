<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropUnique(['codigo_barra']);
            $table->unique(['empresa_id', 'codigo']);
            // codigo_barra es nullable: PostgreSQL permite múltiples NULL en un unique compuesto.
            $table->unique(['empresa_id', 'codigo_barra']);
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropUnique(['rnc']);
            $table->unique(['empresa_id', 'rnc']);
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->dropUnique(['nombre']);
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropUnique(['ncf']);
            // ncf es nullable (empresas sin e-CF activo no lo asignan): múltiples NULL conviven.
            $table->unique(['empresa_id', 'ncf']);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'codigo']);
            $table->dropUnique(['empresa_id', 'codigo_barra']);
            $table->unique('codigo');
            $table->unique('codigo_barra');
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'rnc']);
            $table->unique('rnc');
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'nombre']);
            $table->unique('nombre');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'ncf']);
            $table->unique('ncf');
        });
    }
};
