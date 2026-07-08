<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->boolean('itbis_incluido')->default(false)->after('tipo_comprobante');
            $table->decimal('monto_gravado_18', 14, 2)->default(0)->after('subtotal');
            $table->decimal('monto_gravado_16', 14, 2)->default(0)->after('monto_gravado_18');
            $table->decimal('monto_gravado_0', 14, 2)->default(0)->after('monto_gravado_16');
            $table->decimal('monto_exento', 14, 2)->default(0)->after('monto_gravado_0');
            $table->decimal('itbis_18', 14, 2)->default(0)->after('monto_exento');
            $table->decimal('itbis_16', 14, 2)->default(0)->after('itbis_18');
        });

        // NCF (comprobante fiscal recibido del proveedor) no debe repetirse para el mismo
        // proveedor. Índice parcial porque el campo es nullable y no aplica a toda compra.
        DB::statement('CREATE UNIQUE INDEX compras_proveedor_ncf_unique ON compras (proveedor_id, ncf) WHERE ncf IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS compras_proveedor_ncf_unique');

        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn([
                'itbis_incluido',
                'monto_gravado_18',
                'monto_gravado_16',
                'monto_gravado_0',
                'monto_exento',
                'itbis_18',
                'itbis_16',
            ]);
        });
    }
};
