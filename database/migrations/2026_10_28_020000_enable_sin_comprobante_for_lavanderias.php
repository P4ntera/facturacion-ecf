<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las empresas con perfil lavandería venden sin comprobante por defecto: el cliente
 * lo solicita solo cuando lo necesita. Esta migración habilita el flag para las
 * lavanderías existentes que aún lo tengan desactivado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('empresa_configuracion')
            ->whereIn('empresa_id', function ($query) {
                $query->select('id')
                    ->from('empresas')
                    ->where('perfil', 'lavanderia');
            })
            ->where('permite_ventas_sin_comprobante', false)
            ->update(['permite_ventas_sin_comprobante' => true]);
    }

    public function down(): void
    {
        // No revertimos: no podemos saber cuáles lo tenían habilitado a propósito.
    }
};
