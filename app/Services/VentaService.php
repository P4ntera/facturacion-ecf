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
use App\Models\Caja;
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
     *   cliente_id?: int|null,
     *   user_id?: int|null,
     *   tipo_comprobante?: TipoComprobante|string|null,
     *   sin_comprobante?: bool,
     *   ncf_modifica?: string|null,
     *   descuento_global?: string|float|int|null,
     *   descuento_id?: int|null,
     *   forma_pago?: FormaPago|string|null,
     *   arqueo_caja_id?: int|null,
     *   caja_id?: int|null,
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
     * moneda).
     *
     * cliente_id null = venta al portador (permitida salvo que el comprobante o el crédito exijan
     * cliente). sin_comprobante = true registra la venta sin NCF (tipo_comprobante null), solo si
     * la empresa lo habilitó (EmpresaConfiguracion::permite_ventas_sin_comprobante); no se usa
     * tipo_comprobante => null para esto porque null ya significa "el tipo por defecto".
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

            // Sin cliente_id = venta al portador. Si viene uno, es client-controllable: se
            // revalida que sea de esta empresa y esté activo.
            $cliente = null;

            if (filled($datos['cliente_id'] ?? null)) {
                $cliente = Cliente::where('empresa_id', $empresa->id)->find($datos['cliente_id']);

                if ($cliente === null || ! $cliente->activo) {
                    throw new VentaInvalidaException('El cliente indicado no existe o está inactivo.');
                }
            }

            $cajaId = $this->resolverCaja($datos['caja_id'] ?? null, $empresa);

            $sinComprobante = (bool) ($datos['sin_comprobante'] ?? false);

            if ($sinComprobante && ! $config->permite_ventas_sin_comprobante) {
                throw new VentaInvalidaException('Esta empresa no tiene habilitadas las ventas sin comprobante fiscal: elige un tipo de comprobante.');
            }

            $tipoComprobante = $sinComprobante
                ? null
                : $this->resolverTipoComprobante($datos['tipo_comprobante'] ?? null, $config, $usaEcf);

            // Defensa en profundidad: tipo_comprobante puede venir de una propiedad Livewire
            // pública (PuntoDeVenta::$tipoComprobante) o de una futura API — nunca confiar en que
            // el cliente solo mande tipos de venta. Sin esto, una venta podría consumir (y
            // "quemar") la secuencia NCF de Compras (41), Gastos Menores (43) o Pagos al
            // Exterior (47/B17), que le pertenece a un flujo completamente distinto.
            if ($tipoComprobante !== null && ! $tipoComprobante->esDeVenta()) {
                throw new VentaInvalidaException(
                    "El tipo de comprobante {$tipoComprobante->value} ({$tipoComprobante->etiqueta()}) no es válido para una venta."
                );
            }

            // Una empresa sin e-CF habilitado no puede transmitir nada al PAC: solo puede emitir
            // comprobantes físicos (tipo B). El selector del POS/la config ya filtran esto (ver
            // PuntoDeVenta::tiposComprobante()); esta es la defensa de última línea si igual
            // llega un tipo electrónico explícito (Livewire público, API futura).
            if ($tipoComprobante?->esElectronico() && ! $usaEcf) {
                throw new VentaInvalidaException(
                    "Esta empresa no tiene facturación electrónica habilitada; usa un comprobante físico (tipo B) en vez de {$tipoComprobante->value} ({$tipoComprobante->etiqueta()})."
                );
            }

            // Nota de Crédito (34) y Nota de Débito (33) existen para MODIFICAR un e-CF ya
            // emitido: la norma DGII exige declarar cuál (NCFModificado en el XML/JSON, ver
            // EcfBuilder::idDoc()). Se valida contra una venta real de esta misma empresa —igual
            // que cliente_id/producto_id, ncf_modifica viene del formulario y es client-controllable.
            $ncfModifica = $sinComprobante ? null : ($datos['ncf_modifica'] ?? null);

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

            [$detalles, $productosLineas, $acumulado, $descuentoGlobal] = $this->calcularLineas($datos, $lineas, $config, $estrategia, $empresa, $permitePrecioCero, $cliente);

            $total = $this->calcularTotalFinal($acumulado, $descuentoGlobal);

            // La regla de RNC obligatorio (Crédito Fiscal siempre; Consumo desde
            // Venta::UMBRAL_CONSUMO) es de la NORMA DGII sobre el TIPO de comprobante, no una
            // particularidad del e-CF: aplica igual a un B01/B02 físico. Antes de consumir el
            // NCF: si el comprador falta, no tiene sentido "quemarlo" (el PAC lo rechazaría en
            // el caso electrónico; en el físico, sería un comprobante mal emitido igual).
            $this->validarComprador($tipoComprobante, $cliente, $total);

            $tipoPago = $datos['tipo_pago'] ?? TipoPago::CONTADO;
            $tipoPago = $tipoPago instanceof TipoPago ? $tipoPago : TipoPago::from((int) $tipoPago);

            // La cuenta por cobrar se abre a nombre de alguien: un crédito al portador no se
            // podría cobrar.
            if ($tipoPago === TipoPago::CREDITO && $cliente === null) {
                throw new VentaInvalidaException('Una venta a crédito requiere seleccionar un cliente.');
            }

            // Se asigna DESPUÉS de validar: si algo más falla y la transacción hace rollback, el
            // NCF no se "quema" (el contador también se revierte). Todo tipo_comprobante de venta
            // —físico o electrónico— consume una secuencia real: la diferencia entre B y E es
            // solo si el comprobante se transmite al PAC (ver VentaObserver, que dispara
            // EnviarEcfJob únicamente cuando esElectronica()).
            // Sin comprobante no consume secuencia: la venta queda sin NCF.
            $ncf = $tipoComprobante !== null ? $this->ncfService->siguiente($tipoComprobante, $empresa) : null;

            $fechaLimitePago = $tipoPago === TipoPago::CREDITO
                ? ($datos['fecha_limite_pago'] ?? now()->addDays(30)->toDateString())
                : null;

            $venta = Venta::create([
                // Explícito en vez de depender de que Filament haya asociado el tenant: este
                // service puede invocarse fuera de una request de panel (colas, comandos, tests).
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente?->id,
                'user_id' => $datos['user_id'] ?? null,
                'tipo_comprobante' => $tipoComprobante,
                'ncf' => $ncf,
                'ncf_modifica' => $ncfModifica,
                'forma_pago' => $datos['forma_pago'] ?? FormaPago::EFECTIVO,
                'arqueo_caja_id' => $datos['arqueo_caja_id'] ?? null,
                'caja_id' => $cajaId,
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
                // estado fiscal no aplica — independientemente de si la empresa usa e-CF. Lo mismo
                // una venta sin comprobante.
                'estado_fiscal' => $tipoComprobante?->esElectronico() ? EstadoFiscal::PENDIENTE : EstadoFiscal::NO_APLICA,
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

        $cliente = filled($datos['cliente_id'] ?? null)
            ? Cliente::where('empresa_id', $empresa->id)->find($datos['cliente_id'])
            : null;

        [, , $acumulado, $descuentoGlobal] = $this->calcularLineas($datos, $lineas, $config, $estrategia, $empresa, $permitePrecioCero, $cliente);

        return [
            ...$acumulado,
            'descuento' => $descuentoGlobal,
            'total' => $this->calcularTotalFinal($acumulado, $descuentoGlobal),
        ];
    }

    /**
     * Anula una venta: repone el stock de cada línea y marca la venta como ANULADA. Según su
     * situación fiscal:
     *
     * - e-CF ya aceptado por la DGII (ACEPTADO / ACEPTADO_CONDICIONAL / RFCE): además emite una
     *   Nota de Crédito electrónica (e-CF 34) que la anula ante la DGII — ver emitirNotaCredito().
     *   La venta original conserva su estado_fiscal: para la DGII sigue siendo válida, la Nota es
     *   la que la contrarresta.
     * - e-CF todavía en trámite (PENDIENTE / EN_PROCESO): se rechaza; hay que esperar la
     *   respuesta de la DGII para saber si hace falta Nota de Crédito.
     * - e-CF rechazado, comprobante físico (tipo B) o venta sin comprobante: anulación interna
     *   simple, sin Nota de Crédito (el NCF queda como anulado).
     *
     * @throws VentaYaAnuladaException
     * @throws VentaInvalidaException
     * @throws ArqueoCajaCerradoException
     * @throws CuentaConPagosRegistradosException
     * @throws SecuenciaNcfAgotadaException si hace falta Nota de Crédito y no hay secuencia 34
     */
    public function anular(Venta $venta, string $motivo, ?int $userId = null, ?string $tipoAnulacion608 = null): Venta
    {
        // Queda en motivo_anulacion (608, auditoría) y, si hay Nota de Crédito, viaja a la DGII
        // como RazonModificacion: nunca en blanco.
        if (blank($motivo)) {
            throw new VentaInvalidaException('Debe indicar el motivo de la anulación.');
        }

        return DB::transaction(function () use ($venta, $motivo, $userId) {
            // Bloquea la fila: dos anulaciones simultáneas no pueden emitir dos Notas de Crédito
            // ni reponer el stock dos veces.
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if ($venta->estaAnulada()) {
                throw new VentaYaAnuladaException("La venta #{$venta->id} ya fue anulada anteriormente.");
            }

            if ($venta->esNotaCreditoDeAnulacion()) {
                throw new VentaInvalidaException(
                    "El documento #{$venta->id} es una Nota de Crédito de anulación: no se anula (la venta que anuló ya no se puede revivir)."
                );
            }

            $requiereNotaCredito = $venta->esElectronica() && $venta->estado_fiscal->esAceptado();

            if ($venta->esElectronica() && $venta->estado_fiscal->estaEnTramite()) {
                throw new VentaInvalidaException(
                    "Espere a que la DGII procese la venta #{$venta->id} ({$venta->ncf}) antes de anularla: si la acepta, la anulación requiere una Nota de Crédito."
                );
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

            // Antes de tocar stock: si no hay secuencia 34 disponible, SecuenciaNcfAgotadaException
            // revierte todo y la venta queda intacta.
            if ($requiereNotaCredito) {
                $this->emitirNotaCredito($venta, $userId);
            }

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

            // Para comprobantes físicos (tipo B), el tipo de anulación es obligatorio
            // porque va al Formato 608 de la DGII. Los e-CF no van al 608 (se anulan
            // vía Nota de Crédito E34), así que no lo necesitan.
            if ($venta->tipo_comprobante?->esFisico() && blank($tipoAnulacion608)) {
                throw new VentaInvalidaException(
                    'Debe seleccionar el tipo de anulación para el reporte 608 (comprobante físico).'
                );
            }

            $venta->update([
                'estado' => EstadoVenta::ANULADA,
                'motivo_anulacion' => $motivo,
                'anulada_en' => now(),
                'tipo_anulacion_608' => $venta->tipo_comprobante?->esFisico() ? $tipoAnulacion608 : null,
            ]);

            return $venta->refresh();
        });
    }

    /**
     * Nota de Crédito electrónica (e-CF 34) que anula TOTALMENTE una venta ya aceptada por la
     * DGII: una fila más de ventas, con las mismas líneas y montos que la original (en positivo:
     * el tipo 34 ya indica que es un crédito), ncf_modifica = e-NCF de la original y
     * venta_modificada_id apuntando a ella. VentaObserver la despacha al PAC igual que cualquier
     * e-CF PENDIENTE. No mueve stock (lo repone anular()) ni cuenta en la caja (sin arqueo): es un
     * documento fiscal, no una operación de caja.
     */
    private function emitirNotaCredito(Venta $venta, ?int $userId): Venta
    {
        $empresa = $venta->empresa;

        $notaCredito = Venta::create([
            'empresa_id' => $venta->empresa_id,
            'cliente_id' => $venta->cliente_id,
            'user_id' => $userId,
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO,
            'ncf' => $this->ncfService->siguiente(TipoComprobante::NOTA_CREDITO, $empresa),
            'ncf_modifica' => $venta->ncf,
            'venta_modificada_id' => $venta->id,
            'forma_pago' => $venta->forma_pago,
            'tipo_pago' => $venta->tipo_pago,
            'fecha_limite_pago' => $venta->fecha_limite_pago,
            'fecha' => now(),
            'moneda' => $venta->moneda,
            'tasa_cambio' => $venta->tasa_cambio,
            'subtotal' => $venta->subtotal,
            'descuento' => $venta->descuento,
            'monto_gravado_18' => $venta->monto_gravado_18,
            'monto_gravado_16' => $venta->monto_gravado_16,
            'monto_gravado_0' => $venta->monto_gravado_0,
            'monto_exento' => $venta->monto_exento,
            'itbis_18' => $venta->itbis_18,
            'itbis_16' => $venta->itbis_16,
            'total_itbis' => $venta->total_itbis,
            'total' => $venta->total,
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::PENDIENTE,
        ]);

        $notaCredito->detalles()->createMany(
            $venta->detalles->map(fn ($detalle) => $detalle->only([
                'producto_id', 'presentacion_id', 'descripcion', 'cantidad', 'factor',
                'precio_unitario', 'descuento', 'tasa_itbis', 'itbis_monto', 'subtotal',
            ]))->all()
        );

        return $notaCredito;
    }

    /**
     * Nota de Débito electrónica (e-CF 33): cargo adicional sobre una venta ya aceptada por la
     * DGII. Los montos van positivos (es un cargo, no un crédito). No mueve inventario (no hay
     * producto físico que entre o salga, es un ajuste de precio).
     *
     * @param  array<int, array{producto_id: int, cantidad: float|int, monto: string|float}>  $detalles
     *
     * @throws VentaInvalidaException
     * @throws SecuenciaNcfAgotadaException
     */
    public function emitirNotaDebito(Venta $ventaOriginal, Empresa $empresa, array $detalles, string $motivo): Venta
    {
        $this->validarVentaModificable($ventaOriginal);

        if (empty($detalles)) {
            throw new VentaInvalidaException('Debe incluir al menos un ítem en la Nota de Débito.');
        }

        if (blank($motivo)) {
            throw new VentaInvalidaException('El motivo es obligatorio para Notas de Débito.');
        }

        return DB::transaction(function () use ($ventaOriginal, $empresa, $detalles, $motivo) {
            $config = $empresa->config();
            $ncf = $this->ncfService->siguiente(TipoComprobante::NOTA_DEBITO, $empresa);

            $acumulado = [
                'subtotal' => '0.00', 'monto_gravado_18' => '0.00', 'monto_gravado_16' => '0.00',
                'monto_gravado_0' => '0.00', 'itbis_18' => '0.00', 'itbis_16' => '0.00', 'total_itbis' => '0.00',
            ];
            $detallesCrear = [];

            foreach ($detalles as $detalle) {
                $monto = $this->aMoneda($detalle['monto']);

                if (bccomp($monto, '0', 2) <= 0) {
                    throw new VentaInvalidaException('Los montos de la Nota de Débito deben ser positivos.');
                }

                $producto = Producto::where('empresa_id', $empresa->id)->findOrFail($detalle['producto_id']);
                $cantidad = (float) ($detalle['cantidad'] ?? 1);
                $tasaEfectiva = $config->aplica_itbis ? $producto->tasa_itbis : TasaItbis::CERO;
                $base = bcmul($monto, number_format($cantidad, 4, '.', ''), 2);
                $itbis = bcdiv(bcmul($base, (string) $tasaEfectiva->porcentaje(), 4), '100', 2);

                $acumulado['subtotal'] = bcadd($acumulado['subtotal'], $base, 2);
                $acumulado['total_itbis'] = bcadd($acumulado['total_itbis'], $itbis, 2);

                match ($tasaEfectiva) {
                    TasaItbis::DIECIOCHO => $acumulado['monto_gravado_18'] = bcadd($acumulado['monto_gravado_18'], $base, 2),
                    TasaItbis::DIECISEIS => $acumulado['monto_gravado_16'] = bcadd($acumulado['monto_gravado_16'], $base, 2),
                    TasaItbis::CERO => $acumulado['monto_gravado_0'] = bcadd($acumulado['monto_gravado_0'], $base, 2),
                };

                match ($tasaEfectiva) {
                    TasaItbis::DIECIOCHO => $acumulado['itbis_18'] = bcadd($acumulado['itbis_18'], $itbis, 2),
                    TasaItbis::DIECISEIS => $acumulado['itbis_16'] = bcadd($acumulado['itbis_16'], $itbis, 2),
                    TasaItbis::CERO => null,
                };

                $detallesCrear[] = [
                    'producto_id' => $producto->id,
                    'descripcion' => $producto->nombre,
                    'cantidad' => $cantidad,
                    'factor' => 1,
                    'precio_unitario' => $monto,
                    'descuento' => '0.00',
                    'tasa_itbis' => $tasaEfectiva,
                    'itbis_monto' => $itbis,
                    'subtotal' => $base,
                ];
            }

            $total = bcadd($acumulado['subtotal'], $acumulado['total_itbis'], 2);

            $notaDebito = Venta::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $ventaOriginal->cliente_id,
                'user_id' => auth()->id(),
                'tipo_comprobante' => TipoComprobante::NOTA_DEBITO,
                'ncf' => $ncf,
                'ncf_modifica' => $ventaOriginal->ncf,
                'venta_modificada_id' => $ventaOriginal->id,
                'forma_pago' => $ventaOriginal->forma_pago,
                'tipo_pago' => $ventaOriginal->tipo_pago,
                'fecha' => now(),
                'moneda' => $ventaOriginal->moneda,
                'tasa_cambio' => $ventaOriginal->tasa_cambio,
                'subtotal' => $acumulado['subtotal'],
                'descuento' => '0.00',
                'monto_gravado_18' => $acumulado['monto_gravado_18'],
                'monto_gravado_16' => $acumulado['monto_gravado_16'],
                'monto_gravado_0' => $acumulado['monto_gravado_0'],
                'monto_exento' => '0.00',
                'itbis_18' => $acumulado['itbis_18'],
                'itbis_16' => $acumulado['itbis_16'],
                'total_itbis' => $acumulado['total_itbis'],
                'total' => $total,
                'estado' => EstadoVenta::EMITIDA,
                'estado_fiscal' => EstadoFiscal::PENDIENTE,
                'motivo_anulacion' => $motivo,
            ]);

            $notaDebito->detalles()->createMany($detallesCrear);

            return $notaDebito;
        });
    }

    /**
     * Nota de Crédito parcial (e-CF 34): devuelve ALGUNOS productos de una venta ya aceptada.
     * Usa los precios originales de la venta (no los actuales del producto). Repone stock de los
     * productos devueltos.
     *
     * @param  array<int, array{producto_id: int, cantidad: float|int, presentacion_id?: int|null}>  $detalles
     *
     * @throws VentaInvalidaException
     * @throws SecuenciaNcfAgotadaException
     * @throws StockInsuficienteException  (no debería: es ENTRADA, pero por seguridad)
     */
    public function emitirNotaCreditoParcial(Venta $ventaOriginal, Empresa $empresa, array $detalles, string $motivo): Venta
    {
        $this->validarVentaModificable($ventaOriginal);

        if (empty($detalles)) {
            throw new VentaInvalidaException('Debe incluir al menos un producto a devolver.');
        }

        if (blank($motivo)) {
            throw new VentaInvalidaException('El motivo es obligatorio para devoluciones parciales.');
        }

        return DB::transaction(function () use ($ventaOriginal, $empresa, $detalles, $motivo) {
            $ventaOriginal = Venta::query()->lockForUpdate()->findOrFail($ventaOriginal->id);

            $acumulado = [
                'subtotal' => '0.00', 'monto_gravado_18' => '0.00', 'monto_gravado_16' => '0.00',
                'monto_gravado_0' => '0.00', 'itbis_18' => '0.00', 'itbis_16' => '0.00', 'total_itbis' => '0.00',
            ];
            $detallesCrear = [];
            $stockMovimientos = [];

            foreach ($detalles as $detalle) {
                $cantidad = (float) $detalle['cantidad'];

                if ($cantidad <= 0) {
                    throw new VentaInvalidaException('La cantidad a devolver debe ser mayor que cero.');
                }

                $detalleOriginal = $ventaOriginal->detalles()
                    ->where('producto_id', $detalle['producto_id'])
                    ->when(
                        filled($detalle['presentacion_id'] ?? null),
                        fn ($q) => $q->where('presentacion_id', $detalle['presentacion_id']),
                        fn ($q) => $q->whereNull('presentacion_id'),
                    )
                    ->first();

                if ($detalleOriginal === null) {
                    throw new VentaInvalidaException('El producto no pertenece a la venta original.');
                }

                $yaDevuelto = Venta::where('venta_modificada_id', $ventaOriginal->id)
                    ->where('tipo_comprobante', TipoComprobante::NOTA_CREDITO)
                    ->where('estado', '!=', EstadoVenta::ANULADA)
                    ->join('detalle_ventas', 'ventas.id', '=', 'detalle_ventas.venta_id')
                    ->where('detalle_ventas.producto_id', $detalle['producto_id'])
                    ->when(
                        filled($detalle['presentacion_id'] ?? null),
                        fn ($q) => $q->where('detalle_ventas.presentacion_id', $detalle['presentacion_id']),
                        fn ($q) => $q->whereNull('detalle_ventas.presentacion_id'),
                    )
                    ->sum('detalle_ventas.cantidad');

                $disponible = bcsub((string) $detalleOriginal->cantidad, (string) $yaDevuelto, 3);

                if (bccomp(number_format($cantidad, 3, '.', ''), $disponible, 3) > 0) {
                    $nombre = $detalleOriginal->descripcion;

                    throw new VentaInvalidaException(
                        "No puede devolver {$cantidad} de «{$nombre}». Disponible: {$disponible}."
                    );
                }

                $precioUnitario = (string) $detalleOriginal->precio_unitario;
                $descuentoLinea = (string) $detalleOriginal->descuento;
                $tasaEfectiva = $detalleOriginal->tasa_itbis;
                $cantidadOriginal = (string) $detalleOriginal->cantidad;
                $proporcion = bcdiv(number_format($cantidad, 4, '.', ''), $cantidadOriginal, 12);
                $base = bcadd(bcmul((string) $detalleOriginal->subtotal, $proporcion, 6), '0.005', 2);
                $itbis = bcadd(bcmul((string) $detalleOriginal->itbis_monto, $proporcion, 6), '0.005', 2);

                $acumulado['subtotal'] = bcadd($acumulado['subtotal'], $base, 2);
                $acumulado['total_itbis'] = bcadd($acumulado['total_itbis'], $itbis, 2);

                match ($tasaEfectiva) {
                    TasaItbis::DIECIOCHO => $acumulado['monto_gravado_18'] = bcadd($acumulado['monto_gravado_18'], $base, 2),
                    TasaItbis::DIECISEIS => $acumulado['monto_gravado_16'] = bcadd($acumulado['monto_gravado_16'], $base, 2),
                    TasaItbis::CERO => $acumulado['monto_gravado_0'] = bcadd($acumulado['monto_gravado_0'], $base, 2),
                };

                match ($tasaEfectiva) {
                    TasaItbis::DIECIOCHO => $acumulado['itbis_18'] = bcadd($acumulado['itbis_18'], $itbis, 2),
                    TasaItbis::DIECISEIS => $acumulado['itbis_16'] = bcadd($acumulado['itbis_16'], $itbis, 2),
                    TasaItbis::CERO => null,
                };

                $detallesCrear[] = [
                    'producto_id' => $detalleOriginal->producto_id,
                    'presentacion_id' => $detalleOriginal->presentacion_id,
                    'descripcion' => $detalleOriginal->descripcion,
                    'cantidad' => $cantidad,
                    'factor' => $detalleOriginal->factor,
                    'precio_unitario' => $precioUnitario,
                    'descuento' => $descuentoLinea,
                    'tasa_itbis' => $tasaEfectiva,
                    'itbis_monto' => $itbis,
                    'subtotal' => $base,
                ];

                $stockMovimientos[] = [
                    'producto' => $detalleOriginal->producto,
                    'cantidad' => $cantidad * (float) $detalleOriginal->factor,
                ];
            }

            $total = bcadd($acumulado['subtotal'], $acumulado['total_itbis'], 2);
            $ncf = $this->ncfService->siguiente(TipoComprobante::NOTA_CREDITO, $empresa);

            $notaCredito = Venta::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $ventaOriginal->cliente_id,
                'user_id' => auth()->id(),
                'tipo_comprobante' => TipoComprobante::NOTA_CREDITO,
                'ncf' => $ncf,
                'ncf_modifica' => $ventaOriginal->ncf,
                'venta_modificada_id' => $ventaOriginal->id,
                'forma_pago' => $ventaOriginal->forma_pago,
                'tipo_pago' => $ventaOriginal->tipo_pago,
                'fecha' => now(),
                'moneda' => $ventaOriginal->moneda,
                'tasa_cambio' => $ventaOriginal->tasa_cambio,
                'subtotal' => $acumulado['subtotal'],
                'descuento' => '0.00',
                'monto_gravado_18' => $acumulado['monto_gravado_18'],
                'monto_gravado_16' => $acumulado['monto_gravado_16'],
                'monto_gravado_0' => $acumulado['monto_gravado_0'],
                'monto_exento' => '0.00',
                'itbis_18' => $acumulado['itbis_18'],
                'itbis_16' => $acumulado['itbis_16'],
                'total_itbis' => $acumulado['total_itbis'],
                'total' => $total,
                'estado' => EstadoVenta::EMITIDA,
                'estado_fiscal' => EstadoFiscal::PENDIENTE,
                'motivo_anulacion' => $motivo,
            ]);

            $notaCredito->detalles()->createMany($detallesCrear);

            foreach ($stockMovimientos as $mov) {
                if ($mov['producto'] !== null) {
                    $this->inventarioService->registrarMovimiento(
                        $mov['producto'],
                        TipoMovimiento::ENTRADA,
                        OrigenMovimiento::DEVOLUCION_VENTA,
                        $mov['cantidad'],
                        $notaCredito->id,
                        auth()->id(),
                        $motivo,
                    );
                }
            }

            return $notaCredito;
        });
    }

    /**
     * Validaciones comunes para ND y NC parcial: la venta debe ser electrónica, aceptada, no
     * anulada, y no ser ella misma una nota.
     */
    private function validarVentaModificable(Venta $venta): void
    {
        if (! $venta->esElectronica()) {
            throw new VentaInvalidaException('Solo se pueden emitir notas sobre ventas electrónicas.');
        }

        if (! $venta->estado_fiscal->esAceptado()) {
            throw new VentaInvalidaException('La venta debe estar aceptada por la DGII.');
        }

        if ($venta->estaAnulada()) {
            throw new VentaInvalidaException('No se puede emitir una nota sobre una venta anulada.');
        }

        if ($venta->esNotaCreditoDeAnulacion()) {
            throw new VentaInvalidaException('No se puede emitir una nota sobre otra nota.');
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Crédito Fiscal (31) siempre exige RNC del comprador; Consumo (32) lo exige desde
     * Venta::UMBRAL_CONSUMO; Regímenes Especiales y Gubernamental exigen al menos un cliente (ver
     * Venta::requiereCliente()). Sin comprobante no exige nada. Se evalúa sobre una Venta "en
     * memoria" (sin persistir) porque acá solo se conocen tipo_comprobante y total todavía.
     */
    private function validarComprador(?TipoComprobante $tipoComprobante, ?Cliente $cliente, string $total): void
    {
        if ($tipoComprobante === null) {
            return;
        }

        $venta = new Venta(['tipo_comprobante' => $tipoComprobante, 'total' => $total]);
        $requiereComprador = $venta->requiereComprador();

        if ($cliente === null && $venta->requiereCliente()) {
            $conDocumento = $requiereComprador ? ' con RNC/Cédula' : '';

            throw new VentaInvalidaException(
                "Este tipo de comprobante ({$tipoComprobante->value} — {$tipoComprobante->etiqueta()}) requiere seleccionar un cliente{$conDocumento}."
            );
        }

        if (! $requiereComprador || ! blank($cliente?->documento)) {
            return;
        }

        $mensaje = match (true) {
            $tipoComprobante->esConsumo() => 'Para facturas de consumo de RD$250,000 o más, el cliente con RNC/Cédula es obligatorio.',
            $tipoComprobante->esElectronico() => "La Factura de Crédito Fiscal (e-CF {$tipoComprobante->value}) requiere un cliente con RNC/Cédula.",
            default => "La Factura de Crédito Fiscal ({$tipoComprobante->value}) requiere un cliente con RNC/Cédula.",
        };

        throw new VentaInvalidaException($mensaje);
    }

    /**
     * caja_id viene del selector de caja del POS táctil (client-controllable): igual que
     * cliente_id, se revalida que la caja sea de esta empresa y esté activa.
     *
     * @throws VentaInvalidaException
     */
    private function resolverCaja(int|string|null $cajaId, Empresa $empresa): ?int
    {
        if (blank($cajaId)) {
            return null;
        }

        $caja = Caja::where('empresa_id', $empresa->id)->where('activo', true)->find($cajaId);

        if ($caja === null) {
            throw new VentaInvalidaException('La caja indicada no existe, está inactiva, o no pertenece a esta empresa.');
        }

        return $caja->id;
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
    /**
     * Líneas + descuento global, tal como se guardan (compartido por registrar() y
     * previsualizar(), para que el POS muestre exactamente lo que se cobrará).
     *
     * El descuento global (monto fijo o % de descuento_id sobre el subtotal) se PRORRATEA como
     * descuento de cada línea ANTES de calcular el ITBIS: el impuesto va sobre lo que el cliente
     * realmente paga, y el e-CF cuadra (suma de MontoItem = MontoGravado; MontoGravado + ITBIS =
     * MontoTotal), cosa que la DGII valida. Antes se restaba después del ITBIS, lo que cobraba
     * ITBIS de más y producía e-CF con totales que no cuadraban.
     *
     * Devuelve $acumulado con 'subtotal' BRUTO (antes del descuento global) y los montos
     * gravados/ITBIS NETOS, y el descuento REAL aplicado (bruto − neto, en base sin ITBIS: puede
     * diferir por centavos del pedido por el redondeo por línea). Así venta.total sigue siendo
     * subtotal − descuento + ITBIS.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, string>, 3: string}
     *
     * @throws VentaInvalidaException
     */
    private function calcularLineas(array $datos, array $lineas, EmpresaConfiguracion $config, ImpuestoStrategy $estrategia, Empresa $empresa, bool $permitePrecioCero, ?Cliente $cliente = null): array
    {
        // Si el cliente tiene lista de precio asignada y activa, inyectar el precio de lista
        // en las líneas que no traigan precio_unitario explícito.
        $listaPrecio = $cliente?->listaPrecio;

        if ($listaPrecio !== null && $listaPrecio->activa) {
            $preciosLista = $listaPrecio->productos()
                ->whereIn('producto_id', array_column($lineas, 'producto_id'))
                ->get()
                ->keyBy('id');

            $lineas = array_map(function (array $linea) use ($preciosLista) {
                if (blank($linea['precio_unitario'] ?? null)) {
                    $precioLista = $preciosLista->get($linea['producto_id']);

                    if ($precioLista !== null) {
                        $linea['precio_unitario'] = $precioLista->pivot->precio;
                    }
                }

                return $linea;
            }, $lineas);
        }

        [$detalles, $productosLineas, $acumulado] = $this->procesarLineas($lineas, $config, $estrategia, $empresa, $permitePrecioCero);

        $subtotalBruto = $acumulado['subtotal'];
        $descuentoGlobal = $this->resolverDescuentoGlobal($datos, $subtotalBruto, $empresa);

        // descuento_global viene de una propiedad pública del POS (client-controllable).
        if (bccomp($descuentoGlobal, '0', 2) < 0) {
            throw new VentaInvalidaException('El descuento no puede ser negativo.');
        }

        if (bccomp($descuentoGlobal, $subtotalBruto, 2) > 0) {
            throw new VentaInvalidaException(
                'El descuento (RD$ '.number_format((float) $descuentoGlobal, 2).') no puede ser mayor que el subtotal (RD$ '.number_format((float) $subtotalBruto, 2).').'
            );
        }

        if (bccomp($descuentoGlobal, '0', 2) === 0) {
            return [$detalles, $productosLineas, $acumulado, '0.00'];
        }

        $proporcion = bcdiv($descuentoGlobal, $subtotalBruto, 12);

        [$detalles, $productosLineas, $acumulado] = $this->procesarLineas($lineas, $config, $estrategia, $empresa, $permitePrecioCero, $proporcion);

        $descuentoReal = bcsub($subtotalBruto, $acumulado['subtotal'], 2);
        $acumulado['subtotal'] = $subtotalBruto;

        return [$detalles, $productosLineas, $acumulado, $descuentoReal];
    }

    /**
     * $proporcionDescuentoGlobal (0..1): parte del descuento global que le toca a cada línea,
     * sobre su monto ya neto de su propio descuento, en las mismas unidades que usa la
     * estrategia (base sin ITBIS, o precio con ITBIS incluido). Ver calcularLineas().
     */
    private function procesarLineas(array $lineas, EmpresaConfiguracion $config, ImpuestoStrategy $estrategia, Empresa $empresa, bool $permitePrecioCero = false, string $proporcionDescuentoGlobal = '0'): array
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

            // Ni siquiera con permite_precio_cero: un precio negativo es un crédito disfrazado de
            // venta (eso es una Nota de Crédito).
            if (bccomp($precioUnitario, '0.00', 2) < 0) {
                throw new VentaInvalidaException("El precio unitario de «{$descripcion}» no puede ser negativo.");
            }

            $descuentoLinea = $this->aMoneda($linea['descuento'] ?? '0');
            // Mismo redondeo que las estrategias de ITBIS (cantidad a 4 decimales, half-up).
            $brutoLinea = bcadd(bcmul($precioUnitario, number_format($cantidad, 4, '.', ''), 6), '0.005', 2);

            // El descuento de línea viene del carrito del POS (client-controllable): negativo
            // subiría el precio; mayor que la línea la dejaría en negativo.
            if (bccomp($descuentoLinea, '0.00', 2) < 0) {
                throw new VentaInvalidaException("El descuento de «{$descripcion}» no puede ser negativo.");
            }

            if (bccomp($descuentoLinea, $brutoLinea, 2) > 0) {
                throw new VentaInvalidaException("El descuento de «{$descripcion}» no puede ser mayor que el importe de la línea.");
            }

            if (bccomp($proporcionDescuentoGlobal, '0', 12) > 0) {
                $parteGlobal = bcadd(bcmul(bcsub($brutoLinea, $descuentoLinea, 2), $proporcionDescuentoGlobal, 6), '0.005', 2);
                $descuentoLinea = bccomp(bcadd($descuentoLinea, $parteGlobal, 2), $brutoLinea, 2) > 0
                    ? $brutoLinea
                    : bcadd($descuentoLinea, $parteGlobal, 2);
            }
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
