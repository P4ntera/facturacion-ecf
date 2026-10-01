<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga la compra con el pedido que la originó ("Recibir" en Pedidos de Compra). Nullable: una
 * compra se puede seguir registrando sin pedido. Restrict, no cascade ni null-on-delete: los
 * pedidos no se borran (se cancelan), y no se debe perder el rastro de qué compra lo recibió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->foreignId('pedido_compra_id')
                ->nullable()
                ->after('proveedor_id')
                ->constrained('pedidos_compra')
                ->restrictOnDelete();

            $table->index('pedido_compra_id');
        });

        Schema::table('pedidos_compra', function (Blueprint $table) {
            $table->timestamp('recibido_en')->nullable()->after('cancelado_en');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_compra', function (Blueprint $table) {
            $table->dropColumn('recibido_en');
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pedido_compra_id');
        });
    }
};
