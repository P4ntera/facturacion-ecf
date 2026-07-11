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
        Schema::create('documentos_recibidos', function (Blueprint $table) {
            $table->id();

            // Endpoint que lo recibió y a quién iba dirigido (validado contra EmpresaSettings::rnc).
            $table->string('canal');
            $table->string('rnc_destino');

            // Metadatos best-effort extraídos del XML (pueden venir null si no se pudieron leer).
            $table->string('rnc_emisor')->nullable();
            $table->string('razon_social_emisor')->nullable();
            $table->string('encf')->nullable();
            $table->string('tipo_comprobante')->nullable();
            $table->decimal('monto_total', 14, 2)->nullable();
            $table->date('fecha_emision')->nullable();

            // El XML crudo tal cual se reenvió al PAC (auditoría / reproceso manual).
            $table->longText('xml');

            $table->string('estado_reenvio');
            $table->text('error')->nullable();
            $table->json('respuesta_pac')->nullable();
            $table->string('ip_origen')->nullable();

            // Solo aplica a canal=recepcion: nuestra decisión sobre el e-CF de un proveedor.
            $table->string('aprobacion_comercial')->nullable();

            $table->timestamps();

            $table->index(['canal', 'created_at']);
            $table->index('encf');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentos_recibidos');
    }
};
