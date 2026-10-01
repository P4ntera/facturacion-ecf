<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoOrdenCompra;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\DetalleOrdenCompra;
use App\Models\DetalleRecepcionCompra;
use App\Models\Empresa;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\RecepcionCompra;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OrdenCompraService
{
    public function __construct(
        private readonly InventarioService $inventarioService,
        private readonly CostoPrecioService $costoPrecioService,
    ) {}

    public function generarNumero(Empresa $empresa): string
    {
        $ultimo = OrdenCompra::where('empresa_id', $empresa->id)
            ->orderByDesc('id')
            ->value('numero');

        $siguiente = $ultimo ? ((int) Str::after($ultimo, 'OC-')) + 1 : 1;

        return 'OC-'.str_pad((string) $siguiente, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{
     *   proveedor_id: int,
     *   fecha: string,
     *   fecha_esperada: string|null,
     *   notas: string|null,
     *   lineas: array<int, array{producto_id: int, cantidad_solicitada: float|string, precio_unitario: float|string}>
     * } $datos
     */
    public function crear(array $datos, int $userId, Empresa $empresa): OrdenCompra
    {
        $datos['lineas'] = array_values(array_filter(
            $datos['lineas'] ?? [],
            fn (array $l) => filled($l['producto_id'] ?? null)
                && filled($l['cantidad_solicitada'] ?? null)
                && filled($l['precio_unitario'] ?? null),
        ));

        if (empty($datos['lineas'])) {
            throw new RuntimeException('La orden de compra debe tener al menos una línea.');
        }

        return DB::transaction(function () use ($datos, $userId, $empresa) {
            Proveedor::where('empresa_id', $empresa->id)->findOrFail($datos['proveedor_id']);

            $orden = OrdenCompra::create([
                'empresa_id' => $empresa->id,
                'proveedor_id' => $datos['proveedor_id'],
                'user_id' => $userId,
                'numero' => $this->generarNumero($empresa),
                'fecha' => $datos['fecha'],
                'fecha_esperada' => $datos['fecha_esperada'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'estado' => EstadoOrdenCompra::BORRADOR,
            ]);

            $subtotalTotal = '0';
            $itbisTotal = '0';

            foreach ($datos['lineas'] as $linea) {
                $producto = Producto::where('empresa_id', $empresa->id)
                    ->findOrFail($linea['producto_id']);

                $cantidad = (string) $linea['cantidad_solicitada'];
                $precioUnitario = (string) $linea['precio_unitario'];
                $subtotal = bcmul($cantidad, $precioUnitario, 2);

                $porcentaje = (string) $producto->tasa_itbis->porcentaje();
                $itbis = bcdiv(bcmul($subtotal, $porcentaje, 4), '100', 2);

                DetalleOrdenCompra::create([
                    'orden_compra_id' => $orden->id,
                    'producto_id' => $producto->id,
                    'cantidad_solicitada' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'itbis' => $itbis,
                    'subtotal' => $subtotal,
                ]);

                $subtotalTotal = bcadd($subtotalTotal, $subtotal, 2);
                $itbisTotal = bcadd($itbisTotal, $itbis, 2);
            }

            $orden->update([
                'subtotal' => $subtotalTotal,
                'itbis' => $itbisTotal,
                'total' => bcadd($subtotalTotal, $itbisTotal, 2),
            ]);

            return $orden->refresh();
        });
    }

    /**
     * @param  array<int, array{detalle_orden_compra_id: int, cantidad_recibida: float|string}> $lineas
     */
    public function registrarRecepcion(
        OrdenCompra $ordenCompra,
        array $lineas,
        Empresa $empresa,
        ?string $notas = null,
    ): RecepcionCompra {
        if ((int) $ordenCompra->empresa_id !== (int) $empresa->id) {
            throw new RuntimeException('La orden de compra no pertenece a esta empresa.');
        }

        if (! $ordenCompra->puedeRecibir()) {
            throw new RuntimeException('La orden de compra no está en un estado que permita recepciones.');
        }

        return DB::transaction(function () use ($ordenCompra, $lineas, $empresa, $notas) {
            $recepcion = RecepcionCompra::create([
                'orden_compra_id' => $ordenCompra->id,
                'empresa_id' => $empresa->id,
                'user_id' => auth()->id(),
                'fecha' => now(),
                'notas' => $notas,
            ]);

            foreach ($lineas as $linea) {
                $cantidadRecibida = (string) $linea['cantidad_recibida'];

                if (bccomp($cantidadRecibida, '0', 4) <= 0) {
                    continue;
                }

                $detalle = DetalleOrdenCompra::where('orden_compra_id', $ordenCompra->id)
                    ->findOrFail($linea['detalle_orden_compra_id']);

                $pendiente = $detalle->cantidadPendiente();

                if (bccomp($cantidadRecibida, $pendiente, 4) > 0) {
                    throw new RuntimeException(
                        "No se puede recibir más de lo pendiente para «{$detalle->producto->nombre}»: pendiente {$pendiente}, intentando recibir {$cantidadRecibida}."
                    );
                }

                DetalleRecepcionCompra::create([
                    'recepcion_compra_id' => $recepcion->id,
                    'detalle_orden_compra_id' => $detalle->id,
                    'producto_id' => $detalle->producto_id,
                    'cantidad_recibida' => $cantidadRecibida,
                ]);

                $detalle->update([
                    'cantidad_recibida' => bcadd((string) $detalle->cantidad_recibida, $cantidadRecibida, 4),
                ]);

                $producto = Producto::findOrFail($detalle->producto_id);
                $movimiento = $this->inventarioService->registrarMovimiento(
                    producto: $producto,
                    tipo: TipoMovimiento::ENTRADA,
                    origen: OrigenMovimiento::RECEPCION_ORDEN_COMPRA,
                    cantidad: (float) $cantidadRecibida,
                    referenciaId: $recepcion->id,
                    userId: auth()->id(),
                );

                // La mercancía entra con el precio pactado en la orden: el costo se actualiza
                // según el método de costo de la empresa, igual que en una compra.
                $this->costoPrecioService->aplicarEntrada($producto, $movimiento, $cantidadRecibida, (string) $detalle->precio_unitario, $empresa);
            }

            $ordenCompra->load('detalles');
            $ordenCompra->update([
                'estado' => $ordenCompra->estaCompleta()
                    ? EstadoOrdenCompra::COMPLETADA
                    : EstadoOrdenCompra::RECEPCION_PARCIAL,
            ]);

            return $recepcion;
        });
    }

    public function aprobar(OrdenCompra $ordenCompra): void
    {
        if ($ordenCompra->estado !== EstadoOrdenCompra::BORRADOR) {
            throw new RuntimeException('Solo se pueden aprobar órdenes en borrador.');
        }

        $ordenCompra->update([
            'estado' => EstadoOrdenCompra::ENVIADA,
            'aprobado_por' => auth()->id(),
            'aprobado_en' => now(),
        ]);
    }

    public function enviar(OrdenCompra $ordenCompra): void
    {
        if ($ordenCompra->estado !== EstadoOrdenCompra::BORRADOR) {
            throw new RuntimeException('Solo se pueden enviar órdenes en borrador.');
        }

        $ordenCompra->update(['estado' => EstadoOrdenCompra::ENVIADA]);
    }

    public function cancelar(OrdenCompra $ordenCompra): void
    {
        if (! $ordenCompra->estado->puedeCancelar()) {
            throw new RuntimeException('La orden no se puede cancelar en su estado actual.');
        }

        $ordenCompra->update(['estado' => EstadoOrdenCompra::CANCELADA]);
    }
}
