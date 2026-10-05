<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoOrdenCompra;
use App\Models\Compra;
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

/**
 * Órdenes de compra. La mercancía de una orden se recibe REGISTRANDO LA COMPRA (CompraService):
 * la compra mueve el stock, el costo, la cuenta por pagar y el 606; aquí solo se lleva cuánto de
 * la orden se ha recibido.
 */
class OrdenCompraService
{

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
     * Valida la orden que una compra dice recibir, y la bloquea hasta el fin de la transacción
     * (dos compras simultáneas no pueden recibir lo mismo). orden_compra_id viene del formulario
     * (query string de "Recibir mercancía"): es client-controllable, así que se revalida que sea
     * de la empresa, del mismo proveedor y que admita recepciones.
     */
    public function ordenParaRecibir(mixed $ordenId, Proveedor $proveedor, Empresa $empresa): OrdenCompra
    {
        $orden = OrdenCompra::query()
            ->where('empresa_id', $empresa->id)
            ->lockForUpdate()
            ->find($ordenId);

        if ($orden === null) {
            throw new RuntimeException('La orden de compra indicada no existe.');
        }

        if (! $orden->puedeRecibir()) {
            throw new RuntimeException("La orden de compra {$orden->numero} no admite recepciones ({$orden->estado->etiqueta()}).");
        }

        if ((int) $orden->proveedor_id !== (int) $proveedor->id) {
            throw new RuntimeException("La orden de compra {$orden->numero} es de otro proveedor.");
        }

        return $orden;
    }

    /**
     * Registra contra la orden lo que entró con una compra. NO mueve stock ni costo: eso ya lo
     * hizo CompraService al registrar la compra (una sola entrada de inventario, por la compra).
     * Debe llamarse dentro de la transacción de la compra, con la orden de ordenParaRecibir().
     *
     * Las líneas de productos que no están en la orden se ignoran aquí (el proveedor mandó algo
     * extra: es una línea más de la compra). No se puede recibir más de lo pendiente.
     *
     * @param  array<int, array{producto_id: int|string, cantidad: float|string}>  $lineas  líneas de la compra
     */
    public function registrarRecepcionDeCompra(OrdenCompra $orden, Compra $compra, array $lineas, ?int $userId): RecepcionCompra
    {
        $orden->load('detalles.producto');

        $recepcion = RecepcionCompra::create([
            'orden_compra_id' => $orden->id,
            'compra_id' => $compra->id,
            'empresa_id' => $orden->empresa_id,
            'user_id' => $userId,
            'fecha' => now(),
            'notas' => $compra->ncf ? "Factura {$compra->ncf}" : null,
        ]);

        // Se agrupa por producto: una misma línea de la orden puede venir partida en la compra.
        $porProducto = [];
        foreach ($lineas as $linea) {
            $id = (int) $linea['producto_id'];
            $porProducto[$id] = bcadd($porProducto[$id] ?? '0', (string) $linea['cantidad'], 4);
        }

        foreach ($porProducto as $productoId => $cantidad) {
            $detallesProducto = $orden->detalles->where('producto_id', $productoId)->values();

            if ($detallesProducto->isEmpty()) {
                continue;
            }

            $pendiente = $detallesProducto->reduce(fn (string $suma, DetalleOrdenCompra $d) => bcadd($suma, $d->cantidadPendiente(), 4), '0');

            if (bccomp($cantidad, $pendiente, 4) > 0) {
                $nombre = $detallesProducto->first()->producto->nombre;

                throw new RuntimeException(
                    "No se puede recibir más de lo pendiente en la orden {$orden->numero} para «{$nombre}»: pendiente ".$this->cantidadLegible($pendiente).', recibiendo '.$this->cantidadLegible($cantidad).'.'
                );
            }

            // Si el producto está en varias líneas de la orden, se va llenando la primera primero.
            $restante = $cantidad;
            foreach ($detallesProducto as $detalle) {
                if (bccomp($restante, '0', 4) <= 0) {
                    break;
                }

                $aplicar = bccomp($restante, $detalle->cantidadPendiente(), 4) > 0 ? $detalle->cantidadPendiente() : $restante;

                if (bccomp($aplicar, '0', 4) <= 0) {
                    continue;
                }

                DetalleRecepcionCompra::create([
                    'recepcion_compra_id' => $recepcion->id,
                    'detalle_orden_compra_id' => $detalle->id,
                    'producto_id' => $productoId,
                    'cantidad_recibida' => $aplicar,
                ]);

                $detalle->update(['cantidad_recibida' => bcadd((string) $detalle->cantidad_recibida, $aplicar, 4)]);
                $restante = bcsub($restante, $aplicar, 4);
            }
        }

        $this->actualizarEstadoPorRecepciones($orden);

        return $recepcion;
    }

    /**
     * Al anular una compra que recibió una orden: sus recepciones quedan anuladas (no se borran) y
     * la orden vuelve a tener pendiente lo que se había recibido con ella.
     */
    public function revertirRecepcionDeCompra(Compra $compra): void
    {
        $recepciones = RecepcionCompra::where('compra_id', $compra->id)->whereNull('anulada_en')->with('detalles')->get();

        foreach ($recepciones as $recepcion) {
            foreach ($recepcion->detalles as $detalleRecepcion) {
                $detalleOrden = DetalleOrdenCompra::lockForUpdate()->find($detalleRecepcion->detalle_orden_compra_id);

                if ($detalleOrden === null) {
                    continue;
                }

                $nueva = bcsub((string) $detalleOrden->cantidad_recibida, (string) $detalleRecepcion->cantidad_recibida, 4);
                $detalleOrden->update(['cantidad_recibida' => bccomp($nueva, '0', 4) < 0 ? '0' : $nueva]);
            }

            $recepcion->update(['anulada_en' => now()]);

            $orden = OrdenCompra::lockForUpdate()->find($recepcion->orden_compra_id);
            if ($orden !== null && $orden->estado !== EstadoOrdenCompra::CANCELADA) {
                $this->actualizarEstadoPorRecepciones($orden);
            }
        }
    }

    /** Completada si todo llegó, parcial si llegó algo, enviada si no queda nada recibido. */
    private function actualizarEstadoPorRecepciones(OrdenCompra $orden): void
    {
        $orden->load('detalles');

        $algoRecibido = $orden->detalles->contains(fn (DetalleOrdenCompra $d) => bccomp((string) $d->cantidad_recibida, '0', 4) > 0);

        $orden->update([
            'estado' => match (true) {
                $orden->estaCompleta() => EstadoOrdenCompra::COMPLETADA,
                $algoRecibido => EstadoOrdenCompra::RECEPCION_PARCIAL,
                default => EstadoOrdenCompra::ENVIADA,
            },
        ]);
    }

    /** 4.0000 → "4", 2.5000 → "2.5". */
    private function cantidadLegible(string $cantidad): string
    {
        return rtrim(rtrim($cantidad, '0'), '.');
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
