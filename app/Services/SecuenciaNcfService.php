<?php

namespace App\Services;

use App\Enums\TipoComprobante;
use App\Models\SecuenciaNcf;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SecuenciaNcfService
{
    /**
     * Reserva y devuelve el siguiente NCF para el tipo indicado.
     *
     * Usa SELECT … FOR UPDATE dentro de una transacción para garantizar
     * que dos procesos concurrentes no obtengan el mismo número.
     *
     * @throws RuntimeException cuando no hay secuencia activa/vigente o está agotada.
     */
    public function siguiente(TipoComprobante $tipo): string
    {
        return DB::transaction(function () use ($tipo) {
            /** @var SecuenciaNcf $seq */
            $seq = SecuenciaNcf::where('tipo_comprobante', $tipo)
                ->where('activa', true)
                ->where('vencimiento', '>=', today())
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                throw new RuntimeException(
                    "No existe una secuencia NCF activa y vigente para: {$tipo->etiqueta()}"
                );
            }

            if ($seq->secuencia_actual >= $seq->secuencia_hasta) {
                throw new RuntimeException(
                    "Secuencia NCF agotada para: {$tipo->etiqueta()} "
                    . "(hasta {$seq->secuencia_hasta})"
                );
            }

            $seq->increment('secuencia_actual');

            // Formato DGII: prefijo (B o E) + tipo (2 dígitos) + secuencia (8 dígitos)
            // Ejemplo e-CF: E310000000001  (prefijo=E31, secuencia=0000000001)
            return $seq->prefijo . str_pad($seq->secuencia_actual, 8, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Indica si el tipo de comprobante tiene secuencia activa y disponible.
     */
    public function tieneDisponible(TipoComprobante $tipo): bool
    {
        return SecuenciaNcf::where('tipo_comprobante', $tipo)
            ->where('activa', true)
            ->where('vencimiento', '>=', today())
            ->where('secuencia_actual', '<', DB::raw('secuencia_hasta'))
            ->exists();
    }
}
