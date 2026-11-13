<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Exceptions\VentaInvalidaException;
use App\Models\DetalleTicketLavanderia;
use App\Models\Empresa;
use App\Models\PrecioLavanderia;
use App\Models\Producto;
use App\Models\ServicioLavanderia;
use App\Models\TicketLavanderia;
use App\Models\TipoPrenda;
use App\Models\User;
use App\Models\Venta;
use App\Strategies\Impuesto\ConItbisIncluido;
use App\Strategies\Impuesto\SinItbisIncluido;
use App\Support\ComprobanteVenta;
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

        // Los roles son por empresa: fijar el equipo antes de consultar el permiso (fuera del
        // panel —colas, comandos, tests— no viene fijado).
        setPermissionsTeamId($empresa->id);
        $recepcionista->unsetRelation('roles')->unsetRelation('permissions');
        $puedePrecioManual = $recepcionista->can('lavanderia.precio_manual');

        return DB::transaction(function () use ($empresa, $recepcionista, $datos, $detalles, $puedePrecioManual) {
            $ticket = TicketLavanderia::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $datos['cliente_id'] ?? null,
                'recepcionista_id' => $recepcionista->id,
                'numero' => TicketLavanderia::generarNumero($empresa->id),
                'observaciones' => $datos['observaciones'] ?? null,
                'fecha_prometida' => $datos['fecha_prometida'] ?? null,
            ]);

            $lineasTotales = [];

            foreach ($detalles as $linea) {
                $servicio = ServicioLavanderia::lockForUpdate()->find($linea['servicio_lavanderia_id']);

                // Prioridad de precio: 1) manual del formulario, SOLO si quien crea el ticket tiene
                // `lavanderia.precio_manual` (el precio viene del navegador: sin permiso se ignora);
                // 2) matriz prenda×servicio; 3) precio_base del servicio.
                $precioManual = $puedePrecioManual && filled($linea['precio_unitario'] ?? null)
                    ? $linea['precio_unitario']
                    : null;
                $precioUnitario = $precioManual
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

                $lineasTotales[] = [
                    'servicio' => $servicio,
                    'precio_unitario' => $precioUnitario,
                    'cantidad' => $cantidad,
                ];
            }

            $totales = $this->calcularTotales($empresa, $lineasTotales);

            $ticket->update([
                'total' => $totales['total'],
                'itbis' => $totales['itbis'],
            ]);

            // Si paga ahora, validar monto y generar venta
            if (! empty($datos['pagar_ahora'])) {
                $datosVenta = $datos['datos_venta'] ?? [];

                if (($datosVenta['forma_pago'] ?? null) === FormaPago::EFECTIVO) {
                    $montoEfectivo = (float) ($datosVenta['monto_efectivo'] ?? 0);
                    $totalFinal = (float) $totales['total'];

                    if ($montoEfectivo <= 0) {
                        throw new RuntimeException('Debe ingresar el monto recibido.');
                    }

                    if ($montoEfectivo < $totalFinal) {
                        throw new RuntimeException(
                            'El monto recibido (RD$ '.number_format($montoEfectivo, 2)
                            .') no puede ser menor al total (RD$ '.number_format($totalFinal, 2).').'
                        );
                    }
                }

                $venta = $this->facturar($ticket, $empresa, $datosVenta);
                $ticket->refresh();
            }

            return $ticket;
        });
    }

    /**
     * Totales de un ticket con la MISMA regla de impuestos que usará VentaService al facturarlo:
     * respeta si la empresa cobra ITBIS (aplica_itbis), si sus precios ya lo incluyen
     * (precio_incluye_itbis) y si el servicio lo lleva. Antes el ticket sumaba siempre 18 %
     * encima, y con cualquier otra configuración el ticket y la venta daban totales distintos.
     *
     * @param  array<int, array{servicio?: ServicioLavanderia|null, servicio_lavanderia_id?: int|null, precio_unitario: float|string|null, cantidad: int|string|null}>  $lineas
     * @return array{subtotal: string, itbis: string, total: string}
     */
    public function calcularTotales(Empresa $empresa, array $lineas): array
    {
        $config = $empresa->config();
        $estrategia = $config->precio_incluye_itbis ? new ConItbisIncluido : new SinItbisIncluido;

        $subtotal = '0.00';
        $itbis = '0.00';

        foreach ($lineas as $linea) {
            $servicio = $linea['servicio']
                ?? ServicioLavanderia::where('empresa_id', $empresa->id)->find($linea['servicio_lavanderia_id'] ?? null);

            $precio = (float) ($linea['precio_unitario'] ?? $servicio?->precio_base ?? 0);
            $cantidad = max(1, (int) ($linea['cantidad'] ?? 1));
            $tasa = ($config->aplica_itbis && $servicio?->aplica_itbis) ? TasaItbis::DIECIOCHO : TasaItbis::CERO;

            $desglose = $estrategia->calcular(number_format($precio, 2, '.', ''), (string) $cantidad, '0.00', $tasa);

            $subtotal = bcadd($subtotal, $desglose->base, 2);
            $itbis = bcadd($itbis, $desglose->itbis, 2);
        }

        return ['subtotal' => $subtotal, 'itbis' => $itbis, 'total' => bcadd($subtotal, $itbis, 2)];
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
    public function entregar(TicketLavanderia $ticket, Empresa $empresa): void
    {
        if ($ticket->estado !== EstadoTicketLavanderia::LISTO) {
            throw new RuntimeException('Solo se pueden entregar tickets en estado Listo.');
        }

        if ($ticket->empresa_id !== $empresa->id) {
            throw new RuntimeException('El ticket no pertenece a esta empresa.');
        }

        if ($ticket->estado_pago !== EstadoPagoTicket::PAGADO) {
            throw new RuntimeException('Debe cobrar el ticket antes de entregarlo.');
        }

        DB::transaction(function () use ($ticket) {
            $ticket->lockForUpdate();
            $ticket->refresh();

            if ($ticket->estado !== EstadoTicketLavanderia::LISTO) {
                throw new RuntimeException('El estado del ticket cambió. Intente de nuevo.');
            }

            if ($ticket->estado_pago !== EstadoPagoTicket::PAGADO) {
                throw new RuntimeException('Debe cobrar el ticket antes de entregarlo.');
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

        // Validar monto para pagos en efectivo
        if (($datosVenta['forma_pago'] ?? null) === FormaPago::EFECTIVO) {
            $montoEfectivo = (float) ($datosVenta['monto_efectivo'] ?? 0);

            if ($montoEfectivo <= 0) {
                throw new RuntimeException('Debe ingresar el monto recibido.');
            }

            if ($montoEfectivo < (float) $ticket->total) {
                throw new RuntimeException(
                    'El monto recibido (RD$ '.number_format($montoEfectivo, 2)
                    .') no puede ser menor al total (RD$ '.number_format((float) $ticket->total, 2).').'
                );
            }
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

        // Comprobante: lo que eligió el cajero o, si no eligió, el que corresponde a la
        // configuración de la empresa (sin comprobante solo si lo tiene habilitado).
        $seleccion = $datosVenta['comprobante'] ?? null;
        unset($datosVenta['comprobante']);

        if (! array_key_exists('sin_comprobante', $datosVenta) && ! array_key_exists('tipo_comprobante', $datosVenta)) {
            $datosVenta = array_merge($datosVenta, ComprobanteVenta::aDatosVenta($seleccion, $empresa));
        }

        // El vuelto se calcula aquí contra el total real: el del formulario se calculaba sin
        // ITBIS, y ese valor termina en el e-CF (TablaFormasPago declara efectivo − vuelto).
        if (($datosVenta['forma_pago'] ?? null) === FormaPago::EFECTIVO && (float) ($datosVenta['monto_efectivo'] ?? 0) > 0) {
            $vuelto = bcsub(number_format((float) $datosVenta['monto_efectivo'], 2, '.', ''), (string) $ticket->total, 2);
            $datosVenta['vuelto'] = bccomp($vuelto, '0', 2) > 0 ? $vuelto : null;
        }

        try {
            $venta = $this->ventaService->registrar(
                array_merge($datosVenta, [
                    'lineas' => $lineas,
                    'cliente_id' => $ticket->cliente_id,
                ]),
                $empresa,
            );
        } catch (VentaInvalidaException|SecuenciaNcfAgotadaException $e) {
            // Las pantallas de lavandería solo atrapan RuntimeException: sin esto, una regla
            // fiscal (crédito fiscal sin RNC, secuencia agotada) salía como error 500 en vez de aviso.
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        // Red de seguridad: el ticket y la venta salen de la misma regla; si algún día difieren,
        // se cancela el cobro antes de emitir un comprobante por un monto que no se cobró.
        if (bccomp((string) $venta->total, (string) $ticket->total, 2) !== 0) {
            throw new RuntimeException(
                "El total del ticket (RD$ {$ticket->total}) no coincide con el de la factura (RD$ {$venta->total}). Revise la configuración de ITBIS."
            );
        }

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
        $codigo = 'LAV-'.$servicio->id;
        $tasa = $servicio->aplica_itbis ? TasaItbis::DIECIOCHO : TasaItbis::CERO;

        $producto = Producto::firstOrCreate(
            [
                'empresa_id' => $empresa->id,
                'codigo' => $codigo,
            ],
            [
                'nombre' => 'Lavandería: '.$servicio->nombre,
                'tipo' => TipoProducto::SERVICIO,
                'costo' => 0,
                'precio' => $servicio->precio_base,
                'tasa_itbis' => $tasa,
                'controla_stock' => false,
                'stock' => 0,
                'stock_minimo' => 0,
                'activo' => true,
            ],
        );

        // El producto puente se crea una sola vez: si después cambian el ITBIS del servicio,
        // hay que reflejarlo o la factura seguiría con la tasa vieja.
        if ($producto->tasa_itbis !== $tasa) {
            $producto->update(['tasa_itbis' => $tasa]);
        }

        return $producto;
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
