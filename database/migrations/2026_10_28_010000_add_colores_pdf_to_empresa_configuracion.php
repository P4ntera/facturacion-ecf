<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('color_primario', 7)->default('#00C9D7');
            $table->string('color_secundario', 7)->default('#101820');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->dropColumn(['color_primario', 'color_secundario']);
        });
    }
};
