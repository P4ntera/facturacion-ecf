<?php

namespace App\Services;

use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventarioService
{
    /**
     * Registra una entrada de stock (compra, ajuste positivo, anulación de venta).
     */
    public function entrada(
        Producto $producto,
        float $cantidad,
        OrigenMovimiento $origen,
        int $referenciaId,
        int $userId,
        ?string $observacion = null,
    ): MovimientoInventario {
        return $this->registrar($producto, TipoMovimiento::ENTRADA, $cantidad, $origen, $referenciaId, $userId, $observacion);
    }

    /**
     * Registra una salida de stock (venta, ajuste negativo).
     * Lanza RuntimeException si el producto controla stock y no alcanza.
     */
    public function salida(
        Producto $producto,
        float $cantidad,
        OrigenMovimiento $origen,
        int $referenciaId,
        int $userId,
        ?string $observacion = null,
    ): MovimientoInventario {
        if ($producto->controla_stock) {
            $this->verificarDisponibilidad($producto, $cantidad);
        }

        return $this->registrar($producto, TipoMovimiento::SALIDA, $cantidad, $origen, $referenciaId, $userId, $observacion);
    }

    /**
     * Ajusta el stock a un valor absoluto (toma diferencia como entrada/salida).
     */
    public function ajuste(
        Producto $producto,
        float $stockNuevo,
        int $userId,
        ?string $observacion = null,
    ): MovimientoInventario {
        $diferencia = $stockNuevo - (float) $producto->stock;
        $tipo = $diferencia >= 0 ? TipoMovimiento::ENTRADA : TipoMovimiento::SALIDA;

        return $this->registrar(
            $producto,
            $tipo,
            abs($diferencia),
            OrigenMovimiento::AJUSTE,
            0,
            $userId,
            $observacion,
        );
    }

    /**
     * Verifica que haya stock suficiente.
     *
     * @throws RuntimeException
     */
    public function verificarDisponibilidad(Producto $producto, float $cantidad): void
    {
        if ((float) $producto->stock < $cantidad) {
            throw new RuntimeException(
                "Stock insuficiente para «{$producto->nombre}»: "
                . "disponible {$producto->stock}, solicitado {$cantidad}"
            );
        }
    }

    // -------------------------------------------------------------------------

    private function registrar(
        Producto $producto,
        TipoMovimiento $tipo,
        float $cantidad,
        OrigenMovimiento $origen,
        int $referenciaId,
        int $userId,
        ?string $observacion,
    ): MovimientoInventario {
        return DB::transaction(function () use ($producto, $tipo, $cantidad, $origen, $referenciaId, $userId, $observacion) {
            // Re-read with lock to avoid race conditions
            $producto = Producto::lockForUpdate()->findOrFail($producto->id);

            $stockAnterior = (float) $producto->stock;
            $stockNuevo = $tipo === TipoMovimiento::SALIDA
                ? $stockAnterior - $cantidad
                : $stockAnterior + $cantidad;

            if ($producto->controla_stock) {
                $producto->update(['stock' => $stockNuevo]);
            }

            return MovimientoInventario::create([
                'producto_id'    => $producto->id,
                'tipo'           => $tipo,
                'origen'         => $origen,
                'referencia_id'  => $referenciaId,
                'cantidad'       => $cantidad,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo'    => $producto->controla_stock ? $stockNuevo : $stockAnterior,
                'user_id'        => $userId,
                'observacion'    => $observacion,
            ]);
        });
    }
}
