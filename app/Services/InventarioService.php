<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Exceptions\StockInsuficienteException;
use App\Models\MovimientoInventario;
use App\Models\Producto;

class InventarioService
{
    /**
     * Único punto que mueve stock y registra el Kardex.
     *
     * Debe ejecutarse dentro de una transacción abierta por el llamador (la operación
     * de negocio completa —venta, compra, ajuste— debe ser atómica).
     *
     * @throws StockInsuficienteException si el movimiento dejaría el stock en negativo.
     */
    public function registrarMovimiento(
        Producto $producto,
        TipoMovimiento $tipo,
        OrigenMovimiento $origen,
        float $cantidad,
        ?int $referenciaId = null,
        ?int $userId = null,
        ?string $observacion = null,
    ): ?MovimientoInventario {
        if (! $producto->controla_stock) {
            return null;
        }

        // Re-lee con lock para evitar condiciones de carrera con movimientos concurrentes.
        $producto = Producto::lockForUpdate()->findOrFail($producto->id);

        $stockAnterior = (float) $producto->stock;

        $stockNuevo = match ($tipo) {
            TipoMovimiento::ENTRADA => $stockAnterior + $cantidad,
            TipoMovimiento::SALIDA  => $stockAnterior - $cantidad,
            TipoMovimiento::AJUSTE  => $stockAnterior + $cantidad, // $cantidad ya trae el signo
        };

        if ($stockNuevo < 0) {
            throw new StockInsuficienteException(
                "Stock insuficiente para «{$producto->nombre}»: disponible {$stockAnterior}, solicitado " . abs($cantidad) . '.'
            );
        }

        $producto->update(['stock' => $stockNuevo]);

        return MovimientoInventario::create([
            'producto_id'    => $producto->id,
            'tipo'           => $tipo,
            'origen'         => $origen,
            'referencia_id'  => $referenciaId,
            'cantidad'       => $cantidad,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo'    => $stockNuevo,
            'user_id'        => $userId,
            'observacion'    => $observacion,
        ]);
    }
}
