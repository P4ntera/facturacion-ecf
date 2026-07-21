<?php

use App\Enums\FormaPago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('forma_pago')->default(FormaPago::EFECTIVO->value)->after('tipo_pago');
            $table->foreignId('arqueo_caja_id')
                ->nullable()
                ->after('user_id')
                ->constrained('arqueos_caja')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['arqueo_caja_id']);
            $table->dropColumn(['forma_pago', 'arqueo_caja_id']);
        });
    }
};
