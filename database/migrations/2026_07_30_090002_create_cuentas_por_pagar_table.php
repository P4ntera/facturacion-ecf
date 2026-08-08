<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_por_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->foreignId('compra_id')
                ->unique()
                ->constrained('compras')
                ->restrictOnDelete();
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->restrictOnDelete();
            $table->decimal('monto_total', 14, 2);
            $table->decimal('monto_pagado', 14, 2)->default(0);
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_por_pagar');
    }
};
