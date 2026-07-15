<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Override por cajero: con varias cajas, cada usuario puede fijar su propia
            // impresora de facturación en vez de depender solo de la predeterminada del módulo.
            $table->foreignId('impresora_facturacion_id')
                ->nullable()
                ->after('password')
                ->constrained('impresoras')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impresora_facturacion_id');
        });
    }
};
