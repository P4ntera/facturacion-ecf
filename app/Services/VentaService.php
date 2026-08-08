<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPago;
use App\Exceptions\ArqueoCajaCerradoException;
use App\Exceptions\CuentaConPagosRegistradosException;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\VentaInvalidaException;
use App\Exceptions\VentaYaAnuladaException;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\EmpresaConfiguracion;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Venta;
use App\Strategies\Impuesto\ConItbisIncluido;
use App\Strategies\Impuesto\ImpuestoStrategy;
use App\Strategies\Impuesto\SinItbisIncluido;
use Illuminate\Support\Facades\DB;

class VentaService
{
    public function __construct(
        private readonly SecuenciaNcfService $ncfService,
        private readonly InventarioService $inventarioService,
        private readonly CuentaPorCobrarService $cuentaPorCobrarService,
    ) {}

    /**
     * Registra una venta completa: valida, calcula ITBIS, asigna e-NCF, crea la cabecera y el
     * detalle, y descuenta inventario — todo dentro de una única transacción atómica.
     *
     * @param  array{
     *   cliente_id: int,
     *   user_id?: int|null,
     *   tipo_comprobante?: TipoComprobante|string|null,
     *   descuento_global?: string|float|int|null,
     *   forma_pago?: FormaPago|string|null,
     *   arqueo_caja_id?: int|null,
     *   lineas: array<int, array{
     *     producto_id: int,
     *     presentacion_id?: int|null,
     *     cantidad: float,
     *     precio_unitario?: string|float|int|null,
     *     descuento?: string|float|int|null,
     *   }>,
     * } $datos
     *
     * $empresa: quien llama la resuelve explícitamente (Filament::getTenant() en el panel) — este
     * service no asume ningún tenant ambiente, para poder invocarse igual desde una cola o un
     * comando. Su EmpresaConfiguracion decide comportamiento fiscal (ITBIS, tipo por defecto,
     * moneda); empresa_id en la Venta se deriva del cliente (ya validado), no de este parámetro.
     *
     * @throws VentaInvalidaException
     * @throws SecuenciaNcfAgotadaException
     * @throws StockInsuficienteException
     */
    public function registrar(array $datos, Empresa $empresa): Venta
    {
        return DB::transaction(function () use ($datos, $empresa) {
            $config = $empresa->config();
            $usaEcf = $empresa->usaEcf();

            $lineas = $datos['lineas'] ?? [];

            if (empty($lineas)) {
                throw new VentaInvalidaException('La venta debe tener al menos una línea.');
            }

            $cliente = Cliente::find($datos['cliente_id'] ?? null);

            if ($cliente === null || ! $cliente->activo) {
                throw new VentaInvalidaException('El cliente indicado no existe o está inactivo.');
            }

            $tipoComprobante = $this->resolverTipoComprobante($datos['tipo_comprobante'] ?? null, $config);
            $estrategia = $config->precio_incluye_itbis ? new ConItbisIncluido : new SinItbisIncluido;
            $descuentoGlobal = $this->aMoneda($datos['descuento_global'] ?? '0');

            [$detalles, $productosLineas, $acumulado] = $this->procesarLineas($lineas, $config, $estrategia);

            $total = $this->calcularTotalFinal($acumulado, $descuentoGlobal);

            // Las reglas de RNC obligatorio son de e-CF (DGII); una empresa sin e-CF activo no
            // transmite nada, así que no tiene sentido exigirlas.
            if ($usaEcf) {
                // Antes de consumir el e-NCF: si el comprobante exige RNC del comprador (Crédito
                // Fiscal siempre; Consumo desde Venta::UMBRAL_CONSUMO) y el cliente no lo tiene,
                // no tiene sentido "quemar" un número que el PAC rechazaría de todas formas.
                $this->validarComprador($tipoComprobante, $cliente, $total);
            }

            // Se asigna DESPUÉS de validar: si algo más falla y la transacción hace rollback, el
            // e-NCF no se "quema" (el contador también se revierte). Sin e-CF activo, la venta no
            // consume secuencia ni lleva NCF.
            $ncf = $usaEcf ? $this->ncfService->siguiente($tipoComprobante) : null;

            $tipoPago = $datos['tipo_pago'] ?? TipoPago::CONTADO;
            $tipoPago = $tipoPago instanceof TipoPago ? $tipoPago : TipoPago::from((int) $tipoPago);

            $fechaLimitePago = $tipoPago === TipoPago::CREDITO
                ? ($datos['fecha_limite_pago'] ?? now()->addDays(30)->toDateString())
                : null;

            $venta = Venta::create([
                // Se deriva del cliente (ya validado arriba) en vez de depender de que Filament
                // haya asociado el tenant automáticamente: este service puede invocarse fuera
                // del ciclo de vida de una request de panel (colas, comandos, tests).
                'empresa_id' => $cliente->empresa_id,
                'cliente_id' => $cliente->id,
                'user_id' => $datos['user_id'] ?? null,
                'tipo_comprobante' => $tipoComprobante,
                'ncf' => $ncf,
                'forma_pago' => $datos['forma_pago'] ?? FormaPago::EFECTIVO,
                'arqueo_caja_id' => $datos['arqueo_caja_id'] ?? null,
                'tipo_pago' => $tipoPago,
                'fecha_limite_pago' => $fechaLimitePago,
                'fecha' => now(),
                'moneda' => $config->moneda,
                'subtotal' => $acumulado['subtotal'],
                'descuento' => $descuentoGlobal,
                'monto_gravado_18' => $acumulado['monto_gravado_18'],
                'monto_gravado_16' => $acumulado['monto_gravado_16'],
                'monto_gravado_0' => $acumulado['monto_gravado_0'],
                'monto_exento' => '0.00',
                'itbis_18' => $acumulado['itbis_18'],
                'itbis_16' => $acumulado['itbis_16'],
                'total_itbis' => $acumulado['total_itbis'],
                'total' => $total,
                'estado' => EstadoVenta::EMITIDA,
                // Toda venta con e-NCF asignado debe transmitirse como e-CF: queda PENDIENTE y
                // VentaObserver dispara EnviarEcfJob (a cola, sin bloquear el cobro). Sin e-CF
                // activo en la empresa, no hay nada que transmitir.
                'estado_fiscal' => $usaEcf ? EstadoFiscal::PENDIENTE : EstadoFiscal::NO_APLICA,
            ]);

            $venta->detalles()->createMany($detalles);

            foreach ($productosLineas as $item) {
                $this->inventarioService->registrarMovimiento(
                    $item['producto'],
                    TipoMovimiento::SALIDA,
                    OrigenMovimiento::VENTA,
                    $item['cantidad'],
                    $venta->id,
                    $datos['user_id'] ?? null,
                );
            }

            if ($tipoPago === TipoPago::CREDITO) {
                $this->cuentaPorCobrarService->crearDesdeVenta($venta);
            }

            return $venta->load('detalles.producto', 'cliente');
        });
    }

    /**
     * Calcula el mismo desglose de ITBIS y totales que produciría registrar(), SIN persistir
     * nada (no asigna e-NCF, no crea Venta/DetalleVenta, no mueve stock). Pensado para previews
     * de UI (p. ej. el POS) que deben coincidir exactamente con lo que se guardará.
     *
     * @param  array{
     *   descuento_global?: string|float|int|null,
     *   lineas: array<int, array{
     *     producto_id: int,
     *     presentacion_id?: int|null,
     *     cantidad: float,
     *     precio_unitario?: string|float|int|null,
     *     descuento?: string|float|int|null,
     *   }>,
     * } $datos
     * @return array<string, string>
     *
     * @throws VentaInvalidaException
     */
    public function previsualizar(array $datos, Empresa $empresa): array
    {
        $config = $empresa->config();
        $lineas = $datos['lineas'] ?? [];

        if (empty($lineas)) {
            throw new VentaInvalidaException('La venta debe tener al menos una línea.');
        }

        $estrategia = $config->precio_incluye_itbis ? new ConItbisIncluido : new SinItbisIncluido;
        $descuentoGlobal = $this->aMoneda($datos['descuento_global'] ?? '0');

        [, , $acumulado] = $this->procesarLineas($lineas, $config, $estrategia);

        return [
            ...$acumulado,
            'descuento' => $descuentoGlobal,
            'total' => $this->calcularTotalFinal($acumulado, $descuentoGlobal),
        ];
    }

    /**
     * Anula una venta: repone el stock de cada línea y marca la venta como ANULADA.
     *
     * El e-NCF no se libera ni se borra: queda como comprobante anulado (internamente). La
     * anulación fiscal correcta de un e-CF ya emitido es una Nota de Crédito (Fase 9).
     */
    public function anular(Venta $venta, string $motivo, ?int $userId = null): Venta
    {
        return DB::transaction(function () use ($venta, $motivo, $userId) {
            if ($venta->estaAnulada()) {
                throw new VentaYaAnuladaException("La venta #{$venta->id} ya fue anulada anteriormente.");
            }

            if ($venta->arqueoCaja?->estaCerrado()) {
                throw new ArqueoCajaCerradoException(
                    "No se puede anular la venta #{$venta->id}: pertenece a un arqueo de caja ya cerrado."
                );
            }

            $cuentaPorCobrar = $venta->cuentaPorCobrar;

            if ($cuentaPorCobrar !== null && (float) $cuentaPorCobrar->monto_pagado > 0) {
                throw new CuentaConPagosRegistradosException(
                    "No se puede anular la venta #{$venta->id}: su cuenta por cobrar ya tiene pagos registrados."
                );
            }

            $cuentaPorCobrar?->delete();

            foreach ($venta->detalles as $detalle) {
                $producto = $detalle->producto;

                if ($producto !== null) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::ENTRADA,
                        OrigenMovimiento::ANULACION,
                        (float) $detalle->cantidad * (float) $detalle->factor,
                        $venta->id,
                        $userId,
                        $motivo,
                    );
                }
            }

            $venta->update([
                'estado' => EstadoVenta::ANULADA,
                'motivo_anulacion' => $motivo,
                'anulada_en' => now(),
            ]);

            return $venta->refresh();
        });
    }

    // -------------------------------------------------------------------------

    /**
     * Crédito Fiscal (31) siempre exige RNC del comprador; Consumo (32) lo exige desde
     * Venta::UMBRAL_CONSUMO. Se evalúa sobre una Venta "en memoria" (sin persistir) porque acá
     * solo se conocen tipo_comprobante y total todavía.
     */
    private function validarComprador(TipoComprobante $tipoComprobante, Cliente $cliente, string $total): void
    {
        $requiereComprador = (new Venta(['tipo_comprobante' => $tipoComprobante, 'total' => $total]))->requiereComprador();

        if (! $requiereComprador || ! blank($cliente->documento)) {
            return;
        }

        $mensaje = $tipoComprobante === TipoComprobante::FACTURA_CONSUMO
            ? 'Para facturas de consumo de RD$250,000 o más, el cliente con RNC/Cédula es obligatorio.'
            : 'La Factura de Crédito Fiscal (e-CF 31) requiere un cliente con RNC/Cédula.';

        throw new VentaInvalidaException($mensaje);
    }

    private function resolverTipoComprobante(TipoComprobante|string|null $valor, EmpresaConfiguracion $config): TipoComprobante
    {
        if ($valor instanceof TipoComprobante) {
            return $valor;
        }

        return TipoComprobante::from($valor ?? $config->tipo_comprobante_defecto);
    }

    /**
     * Valida y calcula cada línea (desglose de ITBIS + snapshot para DetalleVenta), y acumula
     * los montos que van en la cabecera de la venta.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array{
     *   0: array<int, array<string, mixed>>,
     *   1: array<int, array{producto: Producto, cantidad: float}>,
     *   2: array<string, string>,
     * }
     *
     * @throws VentaInvalidaException
     */
    private function procesarLineas(array $lineas, EmpresaConfiguracion $config, ImpuestoStrategy $estrategia): array
    {
        $detalles = [];
        $productosLineas = [];
        $acumulado = [
            'subtotal' => '0.00',
            'monto_gravado_18' => '0.00',
            'monto_gravado_16' => '0.00',
            'monto_gravado_0' => '0.00',
            'itbis_18' => '0.00',
            'itbis_16' => '0.00',
            'total_itbis' => '0.00',
        ];

        foreach ($lineas as $linea) {
            $cantidad = (float) ($linea['cantidad'] ?? 0);

            if ($cantidad <= 0) {
                throw new VentaInvalidaException('La cantidad de cada línea debe ser mayor que cero.');
            }

            $producto = Producto::find($linea['producto_id'] ?? null);

            if ($producto === null || ! $producto->activo) {
                $idProducto = $linea['producto_id'] ?? 'desconocido';

                throw new VentaInvalidaException("El producto #{$idProducto} no existe o está inactivo.");
            }

            // presentacion_id viene del formulario/POS (client-controllable): el factor y el
            // nombre de la presentación se resuelven SIEMPRE desde la BD, nunca desde lo que
            // mande el cliente — de lo contrario se podría cobrar una caja pero descontar (y
            // facturar) como si fuera 1 unidad. La restricción producto_id = $producto->id de
            // paso impide referenciar la presentación de otro producto.
            $presentacion = null;
            $factor = 1.0;
            $descripcion = $producto->nombre;

            if (filled($linea['presentacion_id'] ?? null)) {
                $presentacion = ProductoPresentacion::where('producto_id', $producto->id)->find($linea['presentacion_id']);

                if ($presentacion === null) {
                    throw new VentaInvalidaException("La presentación indicada no existe o no pertenece a «{$producto->nombre}».");
                }

                $factor = (float) $presentacion->factor;
                $descripcion = $presentacion->es_base ? $producto->nombre : "{$producto->nombre} - {$presentacion->nombre}";
            }

            $precioUnitario = $this->aMoneda($linea['precio_unitario'] ?? $presentacion?->precio ?? $producto->precio);
            $descuentoLinea = $this->aMoneda($linea['descuento'] ?? '0');
            $tasaEfectiva = $config->aplica_itbis ? $producto->tasa_itbis : TasaItbis::CERO;

            $desglose = $estrategia->calcular($precioUnitario, $cantidad, $descuentoLinea, $tasaEfectiva);

            $acumulado['subtotal'] = bcadd($acumulado['subtotal'], $desglose->base, 2);
            $acumulado['total_itbis'] = bcadd($acumulado['total_itbis'], $desglose->itbis, 2);

            match ($tasaEfectiva) {
                TasaItbis::DIECIOCHO => $acumulado['monto_gravado_18'] = bcadd($acumulado['monto_gravado_18'], $desglose->base, 2),
                TasaItbis::DIECISEIS => $acumulado['monto_gravado_16'] = bcadd($acumulado['monto_gravado_16'], $desglose->base, 2),
                TasaItbis::CERO => $acumulado['monto_gravado_0'] = bcadd($acumulado['monto_gravado_0'], $desglose->base, 2),
            };

            match ($tasaEfectiva) {
                TasaItbis::DIECIOCHO => $acumulado['itbis_18'] = bcadd($acumulado['itbis_18'], $desglose->itbis, 2),
                TasaItbis::DIECISEIS => $acumulado['itbis_16'] = bcadd($acumulado['itbis_16'], $desglose->itbis, 2),
                TasaItbis::CERO => null,
            };

            $detalles[] = [
                'producto_id' => $producto->id,
                'presentacion_id' => $presentacion?->id,
                'descripcion' => $descripcion,
                'cantidad' => $cantidad,
                'factor' => $factor,
                'precio_unitario' => $precioUnitario,
                'descuento' => $descuentoLinea,
                'tasa_itbis' => $tasaEfectiva,
                'itbis_monto' => $desglose->itbis,
                'subtotal' => $desglose->base,
            ];

            // El inventario SIEMPRE se mueve en unidad base: cantidad × factor (una caja de 24
            // consume 24 unidades base aunque la línea diga cantidad 1).
            $productosLineas[] = ['producto' => $producto, 'cantidad' => $cantidad * $factor];
        }

        return [$detalles, $productosLineas, $acumulado];
    }

    /** Normaliza un valor de dinero (string|int|float) a una cadena con escala 2, vía bcmath. */
    private function aMoneda(string|int|float $valor): string
    {
        return bcadd((string) $valor, '0', 2);
    }

    /** @param  array<string, string>  $acumulado */
    private function calcularTotalFinal(array $acumulado, string $descuentoGlobal): string
    {
        return bcadd(bcsub($acumulado['subtotal'], $descuentoGlobal, 2), $acumulado['total_itbis'], 2);
    }
}
