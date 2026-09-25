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
use App\Models\Descuento;
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
     *   ncf_modifica?: string|null,
     *   descuento_global?: string|float|int|null,
     *   descuento_id?: int|null,
     *   forma_pago?: FormaPago|string|null,
     *   arqueo_caja_id?: int|null,
     *   permite_precio_cero?: bool,
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

            $cliente = Cliente::where('empresa_id', $empresa->id)->find($datos['cliente_id'] ?? null);

            if ($cliente === null || ! $cliente->activo) {
                throw new VentaInvalidaException('El cliente indicado no existe o está inactivo.');
            }

            $tipoComprobante = $this->resolverTipoComprobante($datos['tipo_comprobante'] ?? null, $config, $usaEcf);

            // Defensa en profundidad: tipo_comprobante puede venir de una propiedad Livewire
            // pública (PuntoDeVenta::$tipoComprobante) o de una futura API — nunca confiar en que
            // el cliente solo mande tipos de venta. Sin esto, una venta podría consumir (y
            // "quemar") la secuencia NCF de Compras (41), Gastos Menores (43) o Pagos al
            // Exterior (47/B17), que le pertenece a un flujo completamente distinto.
            if (! $tipoComprobante->esDeVenta()) {
                throw new VentaInvalidaException(
                    "El tipo de comprobante {$tipoComprobante->value} ({$tipoComprobante->etiqueta()}) no es válido para una venta."
                );
            }

            // Una empresa sin e-CF habilitado no puede transmitir nada al PAC: solo puede emitir
            // comprobantes físicos (tipo B). El selector del POS/la config ya filtran esto (ver
            // PuntoDeVenta::tiposComprobante()); esta es la defensa de última línea si igual
            // llega un tipo electrónico explícito (Livewire público, API futura).
            if ($tipoComprobante->esElectronico() && ! $usaEcf) {
                throw new VentaInvalidaException(
                    "Esta empresa no tiene facturación electrónica habilitada; usa un comprobante físico (tipo B) en vez de {$tipoComprobante->value} ({$tipoComprobante->etiqueta()})."
                );
            }

            // Nota de Crédito (34) y Nota de Débito (33) existen para MODIFICAR un e-CF ya
            // emitido: la norma DGII exige declarar cuál (NCFModificado en el XML/JSON, ver
            // EcfBuilder::idDoc()). Se valida contra una venta real de esta misma empresa —igual
            // que cliente_id/producto_id, ncf_modifica viene del formulario y es client-controllable.
            $ncfModifica = $datos['ncf_modifica'] ?? null;

            if (in_array($tipoComprobante, [TipoComprobante::NOTA_CREDITO, TipoComprobante::NOTA_DEBITO], true)) {
                if (blank($ncfModifica)) {
                    throw new VentaInvalidaException(
                        "El tipo de comprobante {$tipoComprobante->value} ({$tipoComprobante->etiqueta()}) requiere indicar el NCF que modifica."
                    );
                }

                $existeNcfOriginal = Venta::where('empresa_id', $empresa->id)
                    ->where('ncf', $ncfModifica)
                    ->exists();

                if (! $existeNcfOriginal) {
                    throw new VentaInvalidaException(
                        "El NCF {$ncfModifica} que se pretende modificar no existe en una venta de esta empresa."
                    );
                }
            }

            $estrategia = $config->precio_incluye_itbis ? new ConItbisIncluido : new SinItbisIncluido;
            $permitePrecioCero = (bool) ($datos['permite_precio_cero'] ?? false);

            [$detalles, $productosLineas, $acumulado] = $this->procesarLineas($lineas, $config, $estrategia, $empresa, $permitePrecioCero);

            // El % de descuento_id se aplica sobre el subtotal ya calculado (con descuentos de
            // línea aplicados, antes de ITBIS) — mismo cálculo que hacía PuntoDeVenta en el
            // frontend antes de esta corrección, ahora centralizado aquí para que cualquier
            // llamador (POS, una futura API, un import batch) obtenga el mismo resultado sin
            // tener que reimplementarlo.
            $descuentoGlobal = $this->resolverDescuentoGlobal($datos, $acumulado['subtotal'], $empresa);

            $total = $this->calcularTotalFinal($acumulado, $descuentoGlobal);

            // La regla de RNC obligatorio (Crédito Fiscal siempre; Consumo desde
            // Venta::UMBRAL_CONSUMO) es de la NORMA DGII sobre el TIPO de comprobante, no una
            // particularidad del e-CF: aplica igual a un B01/B02 físico. Antes de consumir el
            // NCF: si el comprador falta, no tiene sentido "quemarlo" (el PAC lo rechazaría en
            // el caso electrónico; en el físico, sería un comprobante mal emitido igual).
            $this->validarComprador($tipoComprobante, $cliente, $total);

            // Se asigna DESPUÉS de validar: si algo más falla y la transacción hace rollback, el
            // NCF no se "quema" (el contador también se revierte). Todo tipo_comprobante de venta
            // —físico o electrónico— consume una secuencia real: la diferencia entre B y E es
            // solo si el comprobante se transmite al PAC (ver VentaObserver, que dispara
            // EnviarEcfJob únicamente cuando esElectronica()).
            $ncf = $this->ncfService->siguiente($tipoComprobante, $empresa);

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
                'ncf_modifica' => $ncfModifica,
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
                // Todo tipo_comprobante ELECTRÓNICO queda PENDIENTE de transmitir: VentaObserver
                // dispara EnviarEcfJob (a cola, sin bloquear el cobro) al ver esElectronica() +
                // PENDIENTE. Un comprobante FÍSICO (tipo B) nunca se transmite al PAC, así que su
                // estado fiscal no aplica — independientemente de si la empresa usa e-CF.
                'estado_fiscal' => $tipoComprobante->esElectronico() ? EstadoFiscal::PENDIENTE : EstadoFiscal::NO_APLICA,
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
     *   descuento_id?: int|null,
     *   permite_precio_cero?: bool,
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
        $permitePrecioCero = (bool) ($datos['permite_precio_cero'] ?? false);

        [, , $acumulado] = $this->procesarLineas($lineas, $config, $estrategia, $empresa, $permitePrecioCero);

        $descuentoGlobal = $this->resolverDescuentoGlobal($datos, $acumulado['subtotal'], $empresa);

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

        $mensaje = match (true) {
            $tipoComprobante->esConsumo() => 'Para facturas de consumo de RD$250,000 o más, el cliente con RNC/Cédula es obligatorio.',
            $tipoComprobante->esElectronico() => "La Factura de Crédito Fiscal (e-CF {$tipoComprobante->value}) requiere un cliente con RNC/Cédula.",
            default => "La Factura de Crédito Fiscal ({$tipoComprobante->value}) requiere un cliente con RNC/Cédula.",
        };

        throw new VentaInvalidaException($mensaje);
    }

    private function resolverTipoComprobante(TipoComprobante|string|null $valor, EmpresaConfiguracion $config, bool $usaEcf): TipoComprobante
    {
        if ($valor instanceof TipoComprobante) {
            return $valor;
        }

        if ($valor !== null) {
            return TipoComprobante::from($valor);
        }

        return TipoComprobante::defectoParaEmpresa($config, $usaEcf);
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
    private function procesarLineas(array $lineas, EmpresaConfiguracion $config, ImpuestoStrategy $estrategia, Empresa $empresa, bool $permitePrecioCero = false): array
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

            $producto = Producto::where('empresa_id', $empresa->id)->find($linea['producto_id'] ?? null);

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

            // Guardrail contra ventas a $0 (línea vacía en el POS, error de captura, o un precio
            // de producto/presentación mal configurado): consumen NCF y mueven stock igual que
            // una venta real. El vendedor debe activar permite_precio_cero a propósito (promos,
            // regalos) para saltarse este bloqueo — nunca es el comportamiento por defecto.
            if (! $permitePrecioCero && bccomp($precioUnitario, '0.00', 2) <= 0) {
                throw new VentaInvalidaException("El precio unitario de «{$descripcion}» debe ser mayor que cero.");
            }

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

    /**
     * Resuelve el descuento global en pesos: si viene descuento_id, se valida que el Descuento
     * pertenezca a esta empresa y esté activo (igual que cliente_id/producto_id, es
     * client-controllable) y se calcula el monto a partir de su porcentaje sobre $subtotal. Si
     * no, se usa descuento_global tal cual (monto fijo, comportamiento previo).
     *
     * @throws VentaInvalidaException
     */
    private function resolverDescuentoGlobal(array $datos, string $subtotal, Empresa $empresa): string
    {
        if (blank($datos['descuento_id'] ?? null)) {
            return $this->aMoneda($datos['descuento_global'] ?? '0');
        }

        $descuento = Descuento::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->find($datos['descuento_id']);

        if ($descuento === null) {
            throw new VentaInvalidaException('El descuento indicado no existe, está inactivo, o no pertenece a esta empresa.');
        }

        return bcdiv(bcmul($subtotal, (string) $descuento->porcentaje, 4), '100', 2);
    }

    /** @param  array<string, string>  $acumulado */
    private function calcularTotalFinal(array $acumulado, string $descuentoGlobal): string
    {
        return bcadd(bcsub($acumulado['subtotal'], $descuentoGlobal, 2), $acumulado['total_itbis'], 2);
    }
}
