<?php

namespace App\Services;

use App\Enums\EstadoPedidoCompra;
use App\Models\DetallePedidoCompra;
use App\Models\PedidoCompra;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PedidoCompraService
{
    public function __construct(
        private readonly CompraService $compraService,
    ) {}

    /**
     * Crea un pedido de compra: cabecera + detalles. Es una SOLICITUD, no una factura: a
     * diferencia de CompraService::crear(), nunca llama a InventarioService ni actualiza
     * Producto::costo — el pedido no mueve stock ni fija costo vigente. Eso solo ocurre
     * cuando más adelante se registra la Compra real correspondiente.
     *
     * @param  array{
     *   proveedor_id: int,
     *   fecha:        \Carbon\Carbon,
     *   notas:        string|null,
     *   lineas: array<int, array{producto_id: int, cantidad: float, costo_unitario: float}>
     * } $datos
     */
    public function crear(array $datos, int $userId): PedidoCompra
    {
        $datos['lineas'] = array_values(array_filter(
            $datos['lineas'] ?? [],
            fn (array $l) => filled($l['producto_id'] ?? null) && filled($l['cantidad'] ?? null) && filled($l['costo_unitario'] ?? null),
        ));

        if (empty($datos['lineas'])) {
            throw new RuntimeException('El pedido de compra debe tener al menos una línea.');
        }

        return DB::transaction(function () use ($datos, $userId) {
            $proveedor    = Proveedor::findOrFail($datos['proveedor_id']);
            $detallesCalc = $this->calcularLineas($datos['lineas']);
            $totales      = $this->calcularTotales($detallesCalc);

            $pedido = PedidoCompra::create([
                'proveedor_id' => $proveedor->id,
                'user_id'      => $userId,
                'fecha'        => $datos['fecha'],
                'notas'        => $datos['notas'] ?? null,
                'estado'       => EstadoPedidoCompra::PENDIENTE,
                ...$totales,
            ]);

            foreach ($detallesCalc as $linea) {
                DetallePedidoCompra::create([
                    'pedido_compra_id' => $pedido->id,
                    'producto_id'      => $linea['producto_id'],
                    'cantidad'         => $linea['cantidad'],
                    'costo_unitario'   => $linea['costo_unitario'],
                    'tasa_itbis'       => $linea['tasa_itbis'],
                    'itbis_monto'      => $linea['itbis_monto'],
                    'subtotal'         => $linea['subtotal'],
                ]);
            }

            return $pedido->load('detalles.producto', 'proveedor');
        });
    }

    /** Cancela un pedido pendiente. Sin DB::transaction: una sola escritura, sin reversión de inventario. */
    public function cancelar(PedidoCompra $pedido, string $motivo, int $userId): PedidoCompra
    {
        if ($pedido->estaCancelado()) {
            throw new RuntimeException('El pedido ya está cancelado.');
        }

        $pedido->update([
            'estado'              => EstadoPedidoCompra::CANCELADO,
            'motivo_cancelacion'  => $motivo,
            'cancelado_en'        => now(),
        ]);

        return $pedido->refresh();
    }

    /**
     * Registra que el pedido fue enviado por correo. No envía el correo (eso lo hace la
     * Action de Filament con Mail::send antes de llamar aquí) — mantiene el I/O de correo
     * fuera del servicio para que sea testeable sin Mail::fake().
     */
    public function marcarEnviado(PedidoCompra $pedido, string $email, int $userId): PedidoCompra
    {
        if ($pedido->estaCancelado()) {
            throw new RuntimeException('No se puede enviar un pedido cancelado.');
        }

        $pedido->update(['enviado_en' => now(), 'enviado_a' => $email]);

        return $pedido->refresh();
    }

    /** Reutiliza CompraService::calcularLineas() para que la tasa de ITBIS nunca se desincronice. */
    public function calcularLineas(array $lineas): array
    {
        return $this->compraService->calcularLineas($lineas, itbisIncluido: false);
    }

    /** Reutiliza CompraService::calcularTotales(). */
    public function calcularTotales(array $lineas): array
    {
        return $this->compraService->calcularTotales($lineas);
    }
}
