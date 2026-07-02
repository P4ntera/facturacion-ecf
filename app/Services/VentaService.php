<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimiento;
use App\Models\DetalleVenta;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VentaService
{
    public function __construct(
        private readonly SecuenciaNcfService $ncfService,
        private readonly InventarioService   $inventarioService,
    ) {}

    /**
     * Crea una venta completa: NCF, detalles, totales e inventario.
     *
     * @param  array{
     *   cliente_id:       int,
     *   tipo_comprobante: TipoComprobante,
     *   fecha:            \Carbon\Carbon,
     *   moneda:           string,
     *   tasa_cambio:      float,
     *   descuento:        float,
     *   lineas: array<int, array{
     *     producto_id:    int,
     *     descripcion:    string|null,
     *     cantidad:       float,
     *     precio_unitario:float,
     *     descuento:      float,
     *     tasa_itbis:     TasaItbis,
     *   }>
     * } $datos
     */
    public function crear(array $datos, int $userId): Venta
    {
        if (empty($datos['lineas'])) {
            throw new RuntimeException('La venta debe tener al menos una línea.');
        }

        return DB::transaction(function () use ($datos, $userId) {
            // 1. Generar NCF
            $ncf = $this->ncfService->siguiente($datos['tipo_comprobante']);

            // 2. Calcular totales por línea
            $detallesCalc = $this->calcularLineas($datos['lineas']);
            $totales      = $this->calcularTotales($detallesCalc, (float) ($datos['descuento'] ?? 0));

            // 3. Crear cabecera de venta
            $venta = Venta::create([
                'cliente_id'       => $datos['cliente_id'],
                'user_id'          => $userId,
                'tipo_comprobante' => $datos['tipo_comprobante'],
                'ncf'              => $ncf,
                'fecha'            => $datos['fecha'],
                'moneda'           => $datos['moneda'] ?? 'DOP',
                'tasa_cambio'      => $datos['tasa_cambio'] ?? 1,
                'estado'           => EstadoVenta::EMITIDA,
                ...$totales,
            ]);

            // 4. Crear detalles y mover inventario
            foreach ($detallesCalc as $linea) {
                DetalleVenta::create([
                    'venta_id'       => $venta->id,
                    'producto_id'    => $linea['producto_id'],
                    'descripcion'    => $linea['descripcion'],
                    'cantidad'       => $linea['cantidad'],
                    'precio_unitario'=> $linea['precio_unitario'],
                    'descuento'      => $linea['descuento'],
                    'tasa_itbis'     => $linea['tasa_itbis'],
                    'itbis_monto'    => $linea['itbis_monto'],
                    'subtotal'       => $linea['subtotal'],
                ]);

                $producto = Producto::find($linea['producto_id']);
                if ($producto) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::SALIDA,
                        OrigenMovimiento::VENTA,
                        (float) $linea['cantidad'],
                        $venta->id,
                        $userId,
                    );
                }
            }

            return $venta->load('detalles.producto', 'cliente');
        });
    }

    /**
     * Anula una venta: revierte inventario y marca como ANULADA.
     */
    public function anular(Venta $venta, string $motivo, int $userId): Venta
    {
        if ($venta->estaAnulada()) {
            throw new RuntimeException('La venta ya está anulada.');
        }

        return DB::transaction(function () use ($venta, $motivo, $userId) {
            // Revertir inventario de cada línea
            foreach ($venta->detalles as $detalle) {
                $producto = $detalle->producto;
                if ($producto) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::ENTRADA,
                        OrigenMovimiento::ANULACION,
                        (float) $detalle->cantidad,
                        $venta->id,
                        $userId,
                        "Anulación venta #{$venta->id}",
                    );
                }
            }

            $venta->update([
                'estado'           => EstadoVenta::ANULADA,
                'motivo_anulacion' => $motivo,
                'anulada_en'       => now(),
            ]);

            return $venta->refresh();
        });
    }

    // -------------------------------------------------------------------------

    /** Calcula subtotal e ITBIS de cada línea. */
    private function calcularLineas(array $lineas): array
    {
        return array_map(function (array $l) {
            $subtotal   = round((float) $l['cantidad'] * (float) $l['precio_unitario'] - (float) ($l['descuento'] ?? 0), 2);
            $porcentaje = $this->porcentajeItbis($l['tasa_itbis']);
            $itbisMonto = round($subtotal * $porcentaje / 100, 2);

            return [
                'producto_id'     => $l['producto_id'],
                'descripcion'     => $l['descripcion'] ?? null,
                'cantidad'        => $l['cantidad'],
                'precio_unitario' => $l['precio_unitario'],
                'descuento'       => (float) ($l['descuento'] ?? 0),
                'tasa_itbis'      => $l['tasa_itbis'],
                'subtotal'        => $subtotal,
                'itbis_monto'     => $itbisMonto,
            ];
        }, $lineas);
    }

    /** Agrupa totales de la cabecera de venta. */
    private function calcularTotales(array $lineas, float $descuentoGlobal): array
    {
        $montoGravado18 = 0.0;
        $montoGravado16 = 0.0;
        $montoGravado0  = 0.0;
        $montoExento    = 0.0;

        foreach ($lineas as $l) {
            match ($l['tasa_itbis']) {
                TasaItbis::DIECIOCHO => $montoGravado18 += $l['subtotal'],
                TasaItbis::DIECISEIS => $montoGravado16 += $l['subtotal'],
                TasaItbis::CERO      => $montoGravado0  += $l['subtotal'],
                TasaItbis::EXENTO    => $montoExento     += $l['subtotal'],
            };
        }

        $subtotal   = round(array_sum(array_column($lineas, 'subtotal')), 2);
        $itbis18    = round($montoGravado18 * 0.18, 2);
        $itbis16    = round($montoGravado16 * 0.16, 2);
        $totalItbis = round($itbis18 + $itbis16, 2);
        $total      = round($subtotal - $descuentoGlobal + $totalItbis, 2);

        return [
            'subtotal'         => $subtotal,
            'descuento'        => $descuentoGlobal,
            'monto_gravado_18' => round($montoGravado18, 2),
            'monto_gravado_16' => round($montoGravado16, 2),
            'monto_gravado_0'  => round($montoGravado0, 2),
            'monto_exento'     => round($montoExento, 2),
            'itbis_18'         => $itbis18,
            'itbis_16'         => $itbis16,
            'total_itbis'      => $totalItbis,
            'total'            => $total,
        ];
    }

    private function porcentajeItbis(TasaItbis $tasa): float
    {
        return match ($tasa) {
            TasaItbis::DIECIOCHO => 18.0,
            TasaItbis::DIECISEIS => 16.0,
            default              => 0.0,
        };
    }
}
