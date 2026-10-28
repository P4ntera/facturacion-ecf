<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El RNC no es obligatorio para todos los perfiles de empresa (ej. lavandería):
 * solo se necesita si la empresa emite comprobantes fiscales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('rnc')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('rnc')->nullable(false)->change();
        });
    }
};
