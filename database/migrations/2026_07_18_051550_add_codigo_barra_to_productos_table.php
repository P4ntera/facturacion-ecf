<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // Postgres permite múltiples NULL en un índice único: los productos sin código de
            // barras (servicios, por ejemplo) no chocan entre sí.
            $table->string('codigo_barra')->nullable()->unique()->after('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('codigo_barra');
        });
    }
};
