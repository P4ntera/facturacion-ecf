<?php

namespace App\Services;

use App\Enums\EstadoDevolucion;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoMovimiento;
use App\Models\Compra;
use App\Models\DetalleCompra;
use App\Models\DetalleDevolucionCompra;
use App\Models\DevolucionCompra;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevolucionCompraService
{
    public function __construct(
        private readonly InventarioService $inventarioService,
    ) {}

    /**
     * Registra una devolución a proveedor sobre una compra ya registrada (nota de crédito).
     * La compra original no se modifica: queda tal cual se digitó para cuadrar con la factura
     * física; la devolución es un documento aparte que revierte stock y se descuenta en reportes.
     *
     * @param  array{
     *   compra_id: int,
     *   fecha:     \Carbon\Carbon,
     *   motivo:    string,
     *   lineas: array<int, array{
     *     detalle_compra_id: int,
     *     cantidad:           float,
     *   }>
     * } $datos
     */
    public function crear(array $datos, int $userId): DevolucionCompra
    {
        if (empty($datos['lineas'])) {
            throw new RuntimeException('La devolución debe tener al menos una línea.');
        }

        return DB::transaction(function () use ($datos, $userId) {
            $compra = Compra::findOrFail($datos['compra_id']);

            if ($compra->estaAnulada()) {
                throw new RuntimeException('No se puede devolver mercancía de una compra anulada.');
            }

            $lineasCalc = [];

            foreach ($datos['lineas'] as $linea) {
                $detalleCompra = DetalleCompra::where('compra_id', $compra->id)
                    ->findOrFail($linea['detalle_compra_id']);

                $cantidad = (float) $linea['cantidad'];

                if ($cantidad <= 0) {
                    throw new RuntimeException('La cantidad a devolver debe ser mayor a cero.');
                }

                $disponible = $detalleCompra->cantidadDisponibleParaDevolver();

                if ($cantidad > $disponible) {
                    throw new RuntimeException(
                        "No puedes devolver {$cantidad} de «{$detalleCompra->producto->nombre}»: solo hay {$disponible} disponible para devolver."
                    );
                }

                $subtotal   = round((float) $detalleCompra->costo_unitario * $cantidad, 2);
                $porcentaje = $detalleCompra->tasa_itbis->porcentaje();
                $itbisMonto = round($subtotal * $porcentaje / 100, 2);

                $lineasCalc[] = [
                    'detalle_compra_id' => $detalleCompra->id,
                    'producto_id'       => $detalleCompra->producto_id,
                    'cantidad'          => $cantidad,
                    'costo_unitario'    => $detalleCompra->costo_unitario,
                    'tasa_itbis'        => $detalleCompra->tasa_itbis,
                    'subtotal'          => $subtotal,
                    'itbis_monto'       => $itbisMonto,
                ];
            }

            $totales = $this->calcularTotales($lineasCalc);

            $devolucion = DevolucionCompra::create([
                'compra_id'    => $compra->id,
                'proveedor_id' => $compra->proveedor_id,
                'user_id'      => $userId,
                'fecha'        => $datos['fecha'],
                'motivo'       => $datos['motivo'],
                'estado'       => EstadoDevolucion::REGISTRADA,
                ...$totales,
            ]);

            foreach ($lineasCalc as $linea) {
                DetalleDevolucionCompra::create([
                    'devolucion_compra_id' => $devolucion->id,
                    'detalle_compra_id'    => $linea['detalle_compra_id'],
                    'producto_id'          => $linea['producto_id'],
                    'cantidad'             => $linea['cantidad'],
                    'costo_unitario'       => $linea['costo_unitario'],
                    'tasa_itbis'           => $linea['tasa_itbis'],
                    'itbis_monto'          => $linea['itbis_monto'],
                    'subtotal'             => $linea['subtotal'],
                ]);

                $producto = Producto::find($linea['producto_id']);

                if ($producto) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::SALIDA,
                        OrigenMovimiento::DEVOLUCION_COMPRA,
                        $linea['cantidad'],
                        $devolucion->id,
                        $userId,
                        "Devolución a proveedor de compra #{$compra->id}",
                    );
                }
            }

            return $devolucion->load('detalles.producto', 'compra.proveedor');
        });
    }

    /**
     * Anula una devolución: revierte el stock salido y la marca como ANULADA.
     */
    public function anular(DevolucionCompra $devolucion, string $motivo, int $userId): DevolucionCompra
    {
        if ($devolucion->estaAnulada()) {
            throw new RuntimeException('La devolución ya está anulada.');
        }

        return DB::transaction(function () use ($devolucion, $motivo, $userId) {
            foreach ($devolucion->detalles as $detalle) {
                $producto = $detalle->producto;

                if ($producto) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::ENTRADA,
                        OrigenMovimiento::ANULACION,
                        (float) $detalle->cantidad,
                        $devolucion->id,
                        $userId,
                        "Anulación devolución #{$devolucion->id}",
                    );
                }
            }

            $devolucion->update([
                'estado'           => EstadoDevolucion::ANULADA,
                'motivo_anulacion' => $motivo,
                'anulada_en'       => now(),
            ]);

            return $devolucion->refresh();
        });
    }

    /** Agrupa totales de la cabecera. Público: reutilizado por DevolucionCompraResource. */
    public function calcularTotales(array $lineas): array
    {
        $montoGravado18 = 0.0;
        $montoGravado16 = 0.0;
        $montoGravado0  = 0.0;

        foreach ($lineas as $l) {
            match ($l['tasa_itbis']) {
                TasaItbis::DIECIOCHO => $montoGravado18 += $l['subtotal'],
                TasaItbis::DIECISEIS => $montoGravado16 += $l['subtotal'],
                TasaItbis::CERO      => $montoGravado0  += $l['subtotal'],
            };
        }

        $subtotal   = round(array_sum(array_column($lineas, 'subtotal')), 2);
        $itbis18    = round($montoGravado18 * 0.18, 2);
        $itbis16    = round($montoGravado16 * 0.16, 2);
        $totalItbis = round($itbis18 + $itbis16, 2);

        return [
            'subtotal'         => $subtotal,
            'monto_gravado_18' => round($montoGravado18, 2),
            'monto_gravado_16' => round($montoGravado16, 2),
            'monto_gravado_0'  => round($montoGravado0, 2),
            'itbis_18'         => $itbis18,
            'itbis_16'         => $itbis16,
            'itbis'            => $totalItbis,
            'total'            => round($subtotal + $totalItbis, 2),
        ];
    }
}
