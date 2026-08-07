<?php

use App\Enums\TipoVenta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('tipo_venta')->default(TipoVenta::CONTABLE->value)->after('tipo');
            $table->string('unidad_base')->default('unidad')->after('tipo_venta');
            $table->decimal('precio_por_peso', 14, 2)->nullable()->after('unidad_base');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['tipo_venta', 'unidad_base', 'precio_por_peso']);
        });
    }
};
