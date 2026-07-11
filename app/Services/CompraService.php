<?php

namespace App\Services;

use App\Enums\EstadoCompra;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimiento;
use App\Models\Compra;
use App\Models\DetalleCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompraService
{
    public function __construct(
        private readonly InventarioService $inventarioService,
        private readonly SecuenciaNcfService $ncfService,
    ) {}

    /**
     * Crea una compra completa: detalles, totales e inventario.
     *
     * @param  array{
     *   proveedor_id:      int,
     *   tipo_comprobante:  \App\Enums\TipoComprobante|null,
     *   ncf:               string|null,
     *   fecha:             \Carbon\Carbon,
     *   itbis_incluido:    bool,
     *   lineas: array<int, array{
     *     producto_id:    int,
     *     cantidad:       float,
     *     costo_unitario: float,
     *   }>
     * } $datos  tipo_comprobante/ncf se ignoran y se autogeneran si el proveedor es informal.
     */
    public function crear(array $datos, int $userId): Compra
    {
        // Descarta líneas incompletas (p. ej. una fila del repeater sin producto seleccionado)
        // en vez de dejar que revienten más abajo con un ModelNotFoundException.
        $datos['lineas'] = array_values(array_filter(
            $datos['lineas'] ?? [],
            fn (array $l) => filled($l['producto_id'] ?? null) && filled($l['cantidad'] ?? null) && filled($l['costo_unitario'] ?? null),
        ));

        if (empty($datos['lineas'])) {
            throw new RuntimeException('La compra debe tener al menos una línea.');
        }

        return DB::transaction(function () use ($datos, $userId) {
            $proveedor     = Proveedor::findOrFail($datos['proveedor_id']);
            $itbisIncluido = (bool) ($datos['itbis_incluido'] ?? false);

            $detallesCalc = $this->calcularLineas($datos['lineas'], $itbisIncluido);
            $totales      = $this->calcularTotales($detallesCalc);

            // Proveedor informal: no emite comprobante fiscal propio, el sistema le genera
            // uno con la secuencia de "Comprobantes de Compras" (tipo 41). Proveedor formal:
            // se registra el comprobante que el proveedor entregó, tal cual lo digitó el usuario.
            if ($proveedor->esInformal()) {
                $tipoComprobante = TipoComprobante::COMPRAS;
                $ncf             = $this->ncfService->siguiente(TipoComprobante::COMPRAS);
            } else {
                $tipoComprobante = $datos['tipo_comprobante'] ?? TipoComprobante::COMPRAS;
                $ncf             = $datos['ncf'] ?? null;
            }

            $compra = Compra::create([
                'proveedor_id'         => $proveedor->id,
                'user_id'              => $userId,
                'tipo_comprobante'     => $tipoComprobante,
                'ncf'                  => $ncf,
                'fecha'                => $datos['fecha'],
                'itbis_incluido'       => $itbisIncluido,
                'monto_total_factura'  => $datos['monto_total_factura'] ?? null,
                'estado'               => EstadoCompra::REGISTRADA,
                ...$totales,
            ]);

            foreach ($detallesCalc as $linea) {
                DetalleCompra::create([
                    'compra_id'      => $compra->id,
                    'producto_id'    => $linea['producto_id'],
                    'cantidad'       => $linea['cantidad'],
                    'costo_unitario' => $linea['costo_unitario'],
                    'tasa_itbis'     => $linea['tasa_itbis'],
                    'itbis_monto'    => $linea['itbis_monto'],
                    'subtotal'       => $linea['subtotal'],
                ]);

                $producto = Producto::find($linea['producto_id']);
                if ($producto) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::ENTRADA,
                        OrigenMovimiento::COMPRA,
                        (float) $linea['cantidad'],
                        $compra->id,
                        $userId,
                    );

                    // Costo vigente = costo (sin ITBIS) de la línea de compra más reciente.
                    $producto->update(['costo' => $linea['costo_unitario']]);
                }
            }

            return $compra->load('detalles.producto', 'proveedor');
        });
    }

    /**
     * Anula una compra: revierte inventario y marca como ANULADA.
     */
    public function anular(Compra $compra, string $motivo, int $userId): Compra
    {
        if ($compra->estaAnulada()) {
            throw new RuntimeException('La compra ya está anulada.');
        }

        return DB::transaction(function () use ($compra, $motivo, $userId) {
            foreach ($compra->detalles as $detalle) {
                $producto = $detalle->producto;
                if ($producto) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::SALIDA,
                        OrigenMovimiento::ANULACION,
                        (float) $detalle->cantidad,
                        $compra->id,
                        $userId,
                        "Anulación compra #{$compra->id}",
                    );
                }
            }

            $compra->update([
                'estado'           => EstadoCompra::ANULADA,
                'motivo_anulacion' => $motivo,
                'anulada_en'       => now(),
            ]);

            return $compra->refresh();
        });
    }

    // -------------------------------------------------------------------------

    /**
     * Calcula costo base, ITBIS y subtotal de cada línea. La tasa de ITBIS la determina
     * el producto (no se digita en la compra). Si $itbisIncluido es true, el costo digitado
     * ya trae el impuesto adentro y se extrae la base; el costo_unitario resultante siempre
     * queda sin ITBIS (igual que DetalleVenta::precio_unitario), tanto en el detalle como en
     * el Producto.costo que se sincroniza con él.
     *
     * Público porque CompraResource lo reutiliza para previsualizar totales en vivo.
     */
    public function calcularLineas(array $lineas, bool $itbisIncluido): array
    {
        return array_map(function (array $l) use ($itbisIncluido) {
            $producto   = Producto::findOrFail($l['producto_id']);
            $tasa       = $producto->tasa_itbis;
            $porcentaje = $tasa->porcentaje();
            $cantidad   = (float) $l['cantidad'];
            $digitado   = (float) $l['costo_unitario'];

            $costoBase = ($itbisIncluido && $porcentaje > 0)
                ? round($digitado / (1 + $porcentaje / 100), 4)
                : $digitado;

            $subtotal   = round($costoBase * $cantidad, 2);
            $itbisMonto = round($subtotal * $porcentaje / 100, 2);

            return [
                'producto_id'    => $l['producto_id'],
                'cantidad'       => $cantidad,
                'costo_unitario' => round($costoBase, 2),
                'tasa_itbis'     => $tasa,
                'subtotal'       => $subtotal,
                'itbis_monto'    => $itbisMonto,
            ];
        }, $lineas);
    }

    /** Agrupa totales de la cabecera de compra. Público: reutilizado por CompraResource. */
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
            'monto_exento'     => 0.00,
            'itbis_18'         => $itbis18,
            'itbis_16'         => $itbis16,
            'itbis'            => $totalItbis,
            'total'            => round($subtotal + $totalItbis, 2),
        ];
    }
}
