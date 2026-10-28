<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\DetalleTicketLavanderia;
use App\Models\Empresa;
use App\Models\PrecioLavanderia;
use App\Models\Producto;
use App\Models\ServicioLavanderia;
use App\Models\TicketLavanderia;
use App\Models\TipoPrenda;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TicketLavanderiaService
{
    public function __construct(
        private readonly VentaService $ventaService,
    ) {}

    /**
     * Crea un ticket de lavandería con sus detalles (prendas + servicios).
     *
     * @param  array{
     *     cliente_id?: int|null,
     *     observaciones?: string|null,
     *     fecha_prometida?: string|null,
     *     pagar_ahora?: bool,
     *     datos_venta?: array,
     *     detalles: array<int, array{
     *         tipo_prenda_id: int,
     *         servicio_lavanderia_id: int,
     *         cantidad: int,
     *         precio_unitario?: float,
     *         observaciones?: string|null,
     *     }>,
     * }  $datos
     */
    public function crear(Empresa $empresa, User $recepcionista, array $datos): TicketLavanderia
    {
        $detalles = $datos['detalles'] ?? [];

        if (empty($detalles)) {
            throw new RuntimeException('El ticket debe tener al menos una prenda.');
        }

        // Validar fecha prometida: mínimo 2 horas a partir de ahora
        if (! empty($datos['fecha_prometida'])) {
            $fechaPrometida = Carbon::parse($datos['fecha_prometida']);
            if ($fechaPrometida->lt(now()->addHours(2))) {
                throw new RuntimeException('La fecha prometida debe ser al menos 2 horas a partir de ahora.');
            }
        }

        // Validar que todas las prendas y servicios pertenezcan a la empresa
        $this->validarDetallesPertenecenAEmpresa($empresa, $detalles);

        return DB::transaction(function () use ($empresa, $recepcionista, $datos, $detalles) {
            $ticket = TicketLavanderia::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $datos['cliente_id'] ?? null,
                'recepcionista_id' => $recepcionista->id,
                'numero' => TicketLavanderia::generarNumero($empresa->id),
                'observaciones' => $datos['observaciones'] ?? null,
                'fecha_prometida' => $datos['fecha_prometida'] ?? null,
            ]);

            $totalNeto = 0;
            $totalItbis = 0;

            foreach ($detalles as $linea) {
                $servicio = ServicioLavanderia::lockForUpdate()->find($linea['servicio_lavanderia_id']);

                // Prioridad de precio: 1) manual del formulario, 2) matriz prenda×servicio, 3) precio_base del servicio
                $precioUnitario = $linea['precio_unitario']
                    ?? PrecioLavanderia::precioParaCombinacion($empresa->id, $linea['tipo_prenda_id'], $linea['servicio_lavanderia_id'])
                    ?? $servicio->precio_base;
                $cantidad = max(1, (int) $linea['cantidad']);
                $subtotal = round($precioUnitario * $cantidad, 2);

                DetalleTicketLavanderia::create([
                    'ticket_lavanderia_id' => $ticket->id,
                    'tipo_prenda_id' => $linea['tipo_prenda_id'],
                    'servicio_lavanderia_id' => $linea['servicio_lavanderia_id'],
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotal,
                    'observaciones' => $linea['observaciones'] ?? null,
                ]);

                $totalNeto += $subtotal;

                if ($servicio->aplica_itbis) {
                    $totalItbis += round($subtotal * 0.18, 2);
                }
            }

            $ticket->update([
                'total' => $totalNeto + $totalItbis,
                'itbis' => $totalItbis,
            ]);

            // Si paga ahora, generar venta inmediatamente
            if (! empty($datos['pagar_ahora'])) {
                $venta = $this->facturar($ticket, $empresa, $datos['datos_venta'] ?? []);
                $ticket->refresh();
            }

            return $ticket;
        });
    }

    /**
     * Cambia el estado del ticket al siguiente en el flujo:
     * RECIBIDO → LISTO (EN_PROCESO se conserva por compatibilidad con tickets antiguos).
     */
    public function avanzarEstado(TicketLavanderia $ticket): void
    {
        $siguiente = match ($ticket->estado) {
            EstadoTicketLavanderia::RECIBIDO,
            EstadoTicketLavanderia::EN_PROCESO => EstadoTicketLavanderia::LISTO,
            default => throw new RuntimeException("No se puede avanzar el ticket en estado {$ticket->estado->etiqueta()}."),
        };

        DB::transaction(function () use ($ticket, $siguiente) {
            $ticket->lockForUpdate();
            $ticket->refresh();

            // Revalidar: solo avanzar si sigue en un estado que permite avance.
            if (! in_array($ticket->estado, [
                EstadoTicketLavanderia::RECIBIDO,
                EstadoTicketLavanderia::EN_PROCESO,
            ], true)) {
                throw new RuntimeException('El estado del ticket cambió. Intente de nuevo.');
            }

            $ticket->update(['estado' => $siguiente]);
        });
    }

    /**
     * Entrega las prendas al cliente. Si el pago estaba pendiente, factura primero.
     */
    public function entregar(TicketLavanderia $ticket, Empresa $empresa, array $datosVenta = []): void
    {
        if ($ticket->estado !== EstadoTicketLavanderia::LISTO) {
            throw new RuntimeException('Solo se pueden entregar tickets en estado Listo.');
        }

        if ($ticket->empresa_id !== $empresa->id) {
            throw new RuntimeException('El ticket no pertenece a esta empresa.');
        }

        DB::transaction(function () use ($ticket, $empresa, $datosVenta) {
            $ticket->lockForUpdate();
            $ticket->refresh();

            if ($ticket->estado !== EstadoTicketLavanderia::LISTO) {
                throw new RuntimeException('El estado del ticket cambió. Intente de nuevo.');
            }

            // Si no ha pagado, facturar ahora
            if ($ticket->estado_pago === EstadoPagoTicket::PENDIENTE) {
                $this->facturar($ticket, $empresa, $datosVenta);
            }

            $ticket->update([
                'estado' => EstadoTicketLavanderia::ENTREGADO,
                'entregado_en' => now(),
            ]);
        });
    }

    /**
     * Cobra un ticket pendiente de pago sin cambiar su estado de flujo.
     * Se usa cuando el cliente paga antes de la entrega.
     */
    public function cobrar(TicketLavanderia $ticket, Empresa $empresa, array $datosVenta = []): Venta
    {
        if ($ticket->estado_pago === EstadoPagoTicket::PAGADO) {
            throw new RuntimeException('Este ticket ya fue cobrado.');
        }

        if ($ticket->estado === EstadoTicketLavanderia::CANCELADO) {
            throw new RuntimeException('No se puede cobrar un ticket cancelado.');
        }

        if ($ticket->empresa_id !== $empresa->id) {
            throw new RuntimeException('El ticket no pertenece a esta empresa.');
        }

        return DB::transaction(function () use ($ticket, $empresa, $datosVenta) {
            $ticket->lockForUpdate();
            $ticket->refresh();

            if ($ticket->estado_pago === EstadoPagoTicket::PAGADO) {
                throw new RuntimeException('Este ticket ya fue cobrado.');
            }

            return $this->facturar($ticket, $empresa, $datosVenta);
        });
    }

    /**
     * Cancela un ticket (solo si no ha sido entregado).
     */
    public function cancelar(TicketLavanderia $ticket): void
    {
        if ($ticket->estado === EstadoTicketLavanderia::ENTREGADO) {
            throw new RuntimeException('No se puede cancelar un ticket ya entregado.');
        }

        if ($ticket->estado === EstadoTicketLavanderia::CANCELADO) {
            throw new RuntimeException('El ticket ya está cancelado.');
        }

        DB::transaction(function () use ($ticket) {
            $ticket->lockForUpdate();
            $ticket->refresh();

            if (in_array($ticket->estado, [EstadoTicketLavanderia::ENTREGADO, EstadoTicketLavanderia::CANCELADO])) {
                throw new RuntimeException('No se puede cancelar este ticket.');
            }

            $ticket->update(['estado' => EstadoTicketLavanderia::CANCELADO]);
        });
    }

    /**
     * Genera la venta/factura asociada al ticket.
     */
    private function facturar(TicketLavanderia $ticket, Empresa $empresa, array $datosVenta = []): Venta
    {
        $detalles = $ticket->detalles()->with('servicioLavanderia')->get();

        // VentaService requiere producto_id en cada línea. Para lavandería creamos/reutilizamos
        // un producto genérico de tipo SERVICIO por cada ServicioLavanderia de la empresa
        // (sin stock, precio = 0 porque el precio real viene del ticket).
        $lineas = $detalles->map(function (DetalleTicketLavanderia $d) use ($empresa) {
            $producto = $this->obtenerProductoServicio($empresa, $d->servicioLavanderia);

            return [
                'producto_id' => $producto->id,
                'cantidad' => $d->cantidad,
                'precio_unitario' => $d->precio_unitario,
            ];
        })->toArray();

        $venta = $this->ventaService->registrar(
            array_merge([
                'sin_comprobante' => true,
            ], $datosVenta, [
                'lineas' => $lineas,
                'cliente_id' => $ticket->cliente_id,
            ]),
            $empresa,
        );

        $ticket->update([
            'venta_id' => $venta->id,
            'estado_pago' => EstadoPagoTicket::PAGADO,
        ]);

        return $venta;
    }

    /**
     * Obtiene o crea un producto de tipo SERVICIO asociado a un ServicioLavanderia.
     * Se usa como puente para que VentaService pueda facturar servicios de lavandería.
     */
    private function obtenerProductoServicio(Empresa $empresa, ServicioLavanderia $servicio): Producto
    {
        $codigo = 'LAV-' . $servicio->id;

        return Producto::firstOrCreate(
            [
                'empresa_id' => $empresa->id,
                'codigo' => $codigo,
            ],
            [
                'nombre' => 'Lavandería: ' . $servicio->nombre,
                'tipo' => TipoProducto::SERVICIO,
                'costo' => 0,
                'precio' => $servicio->precio_base,
                'tasa_itbis' => $servicio->aplica_itbis ? TasaItbis::DIECIOCHO : TasaItbis::CERO,
                'controla_stock' => false,
                'stock' => 0,
                'stock_minimo' => 0,
                'activo' => true,
            ],
        );
    }

    /**
     * Valida que los tipos de prenda y servicios referenciados existan y pertenezcan a la empresa.
     */
    private function validarDetallesPertenecenAEmpresa(Empresa $empresa, array $detalles): void
    {
        $tipoPrendaIds = array_unique(array_column($detalles, 'tipo_prenda_id'));
        $servicioIds = array_unique(array_column($detalles, 'servicio_lavanderia_id'));

        $tiposExistentes = TipoPrenda::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereIn('id', $tipoPrendaIds)
            ->count();

        if ($tiposExistentes !== count($tipoPrendaIds)) {
            throw new RuntimeException('Uno o más tipos de prenda no pertenecen a esta empresa o están inactivos.');
        }

        $serviciosExistentes = ServicioLavanderia::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereIn('id', $servicioIds)
            ->count();

        if ($serviciosExistentes !== count($servicioIds)) {
            throw new RuntimeException('Uno o más servicios no pertenecen a esta empresa o están inactivos.');
        }
    }
}
