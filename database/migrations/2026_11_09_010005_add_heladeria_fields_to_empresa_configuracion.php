<?php

use App\Enums\ModoPrecioHelado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('modo_precio_helado')->default(ModoPrecioHelado::POR_PRESENTACION->value)
                ->after('color_secundario');
            $table->decimal('precio_scoop_normal', 15, 2)->default(0)
                ->after('modo_precio_helado');
            $table->decimal('precio_scoop_premium', 15, 2)->default(0)
                ->after('precio_scoop_normal');
            $table->boolean('permitir_scoop_extra')->default(true)
                ->after('precio_scoop_premium');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->dropColumn([
                'modo_precio_helado',
                'precio_scoop_normal',
                'precio_scoop_premium',
                'permitir_scoop_extra',
            ]);
        });
    }
};
