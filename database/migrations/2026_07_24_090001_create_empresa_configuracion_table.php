<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_configuracion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->unique()
                ->constrained('empresas')
                ->cascadeOnDelete();

            // Comportamiento de facturación (antes FacturacionSettings, global).
            $table->boolean('aplica_itbis')->default(true);
            $table->boolean('precio_incluye_itbis')->default(false);
            $table->string('tasa_itbis_defecto')->default('18');
            $table->string('tipo_comprobante_defecto')->default('32');
            $table->string('moneda')->default('DOP');

            // Integración PAC/DGII (antes EmpresaSettings, global). api_key encriptada: nunca en
            // texto plano en BD, logs ni respuestas.
            $table->text('dgii_api_key')->nullable();
            $table->string('dgii_ambiente')->default('TesteCF');
            $table->string('dgii_base_url')->nullable();

            // Certificado digital (.p12) para firmar ante el PAC.
            $table->string('certificado_path')->nullable();
            $table->text('certificado_password')->nullable();
            $table->date('certificado_vence')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_configuracion');
    }
};
