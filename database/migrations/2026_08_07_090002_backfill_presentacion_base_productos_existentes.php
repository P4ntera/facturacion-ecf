<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('productos')
            ->where('tipo_venta', 'contable')
            ->orderBy('id')
            ->chunkById(200, function ($productos) {
                $ahora = now();

                $filas = $productos->map(fn ($producto) => [
                    'empresa_id' => $producto->empresa_id,
                    'producto_id' => $producto->id,
                    'nombre' => 'Unidad',
                    'factor' => 1,
                    'codigo_barra' => $producto->codigo_barra,
                    'precio' => $producto->precio,
                    'es_base' => true,
                    'activa' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ])->all();

                if ($filas !== []) {
                    DB::table('producto_presentaciones')->insert($filas);
                }
            });
    }

    public function down(): void
    {
        DB::table('producto_presentaciones')->where('nombre', 'Unidad')->where('es_base', true)->delete();
    }
};
