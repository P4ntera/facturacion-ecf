<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Textos configurables por documento impreso (ver App\Enums\DocumentoImpreso):
     * {"venta_ticket": {"pie": "..."}, "cotizacion": {"pie": "...", "incluye": "..."}}.
     * null = nada configurado, cada documento usa sus textos por defecto.
     */
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->json('textos_documentos')->nullable()->after('color_accent_pos');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->dropColumn('textos_documentos');
        });
    }
};
