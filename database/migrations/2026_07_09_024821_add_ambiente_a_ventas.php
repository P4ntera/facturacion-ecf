<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // Ambiente del PAC (TesteCF/CerteCF/eCF) con el que se envió/respondió este e-CF —
            // snapshot al momento del envío, no la configuración actual de la empresa.
            $table->string('ambiente')->nullable()->after('xml_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('ambiente');
        });
    }
};
