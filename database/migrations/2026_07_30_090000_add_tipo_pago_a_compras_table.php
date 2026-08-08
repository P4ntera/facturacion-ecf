<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->smallInteger('tipo_pago')->default(1)->after('itbis_incluido');
            $table->date('fecha_vencimiento')->nullable()->after('tipo_pago');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn(['tipo_pago', 'fecha_vencimiento']);
        });
    }
};
