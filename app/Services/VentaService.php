<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimiento;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\VentaInvalidaException;
use App\Exceptions\VentaYaAnuladaException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Venta;
use App\Settings\FacturacionSettings;
use App\Strategies\Impuesto\ConItbisIncluido;
use App\Strategies\Impuesto\ImpuestoStrategy;
use App\Strategies\Impuesto\SinItbisIncluido;
use Illuminate\Support\Facades\DB;

class VentaService
{
    public function __construct(
        private readonly SecuenciaNcfService $ncfService,
        private readonly InventarioService $inventarioService,
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
     *   lineas: array<int, array{
     *     producto_id: int,
     *     cantidad: float,
     *     precio_unitario?: string|float|int|null,
     *     descuento?: string|float|int|null,
     *   }>,
     * } $datos
     *
     * @throws VentaInvalidaException
     * @throws SecuenciaNcfAgotadaException
     * @throws StockInsuficienteException
     */
    public function registrar(array $datos): Venta
    {
        return DB::transaction(function () use ($datos) {
            $settings = app(FacturacionSettings::class);

            $lineas = $datos['lineas'] ?? [];

            if (empty($lineas)) {
                throw new VentaInvalidaException('La venta debe tener al menos una línea.');
            }

            $cliente = Cliente::find($datos['cliente_id'] ?? null);

            if ($cliente === null || ! $cliente->activo) {
                throw new VentaInvalidaException('El cliente indicado no existe o está inactivo.');
            }

            $tipoComprobante = $this->resolverTipoComprobante($datos['tipo_comprobante'] ?? null, $settings);
            $estrategia = $settings->precio_incluye_itbis ? new ConItbisIncluido : new SinItbisIncluido;
            $descuentoGlobal = $this->aMoneda($datos['descuento_global'] ?? '0');

            [$detalles, $productosLineas, $acumulado] = $this->procesarLineas($lineas, $settings, $estrategia);

            $total = $this->calcularTotalFinal($acumulado, $descuentoGlobal);

            // Antes de consumir el e-NCF: si el comprobante exige RNC del comprador (Crédito
            // Fiscal siempre; Consumo desde Venta::UMBRAL_CONSUMO) y el cliente no lo tiene, no
            // tiene sentido "quemar" un número que el PAC rechazaría de todas formas.
            $this->validarComprador($tipoComprobante, $cliente, $total);

            // Se asigna DESPUÉS de validar: si algo más falla y la transacción hace rollback, el
            // e-NCF no se "quema" (el contador también se revierte).
            $ncf = $this->ncfService->siguiente($tipoComprobante);

            $venta = Venta::create([
                'cliente_id' => $cliente->id,
                'user_id' => $datos['user_id'] ?? null,
                'tipo_comprobante' => $tipoComprobante,
                'ncf' => $ncf,
                'fecha' => now(),
                'moneda' => $settings->moneda,
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
                // VentaObserver dispara EnviarEcfJob (a cola, sin bloquear el cobro).
                'estado_fiscal' => EstadoFiscal::PENDIENTE,
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
     *     cantidad: float,
     *     precio_unitario?: string|float|int|null,
     *     descuento?: string|float|int|null,
     *   }>,
     * } $datos
     * @return array<string, string>
     *
     * @throws VentaInvalidaException
     */
    public function previsualizar(array $datos): array
    {
        $settings = app(FacturacionSettings::class);
        $lineas = $datos['lineas'] ?? [];

        if (empty($lineas)) {
            throw new VentaInvalidaException('La venta debe tener al menos una línea.');
        }

        $estrategia = $settings->precio_incluye_itbis ? new ConItbisIncluido : new SinItbisIncluido;
        $descuentoGlobal = $this->aMoneda($datos['descuento_global'] ?? '0');

        [, , $acumulado] = $this->procesarLineas($lineas, $settings, $estrategia);

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

            foreach ($venta->detalles as $detalle) {
                $producto = $detalle->producto;

                if ($producto !== null) {
                    $this->inventarioService->registrarMovimiento(
                        $producto,
                        TipoMovimiento::ENTRADA,
                        OrigenMovimiento::ANULACION,
                        (float) $detalle->cantidad,
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

    private function resolverTipoComprobante(TipoComprobante|string|null $valor, FacturacionSettings $settings): TipoComprobante
    {
        if ($valor instanceof TipoComprobante) {
            return $valor;
        }

        return TipoComprobante::from($valor ?? $settings->tipo_comprobante_defecto);
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
    private function procesarLineas(array $lineas, FacturacionSettings $settings, ImpuestoStrategy $estrategia): array
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

            $precioUnitario = $this->aMoneda($linea['precio_unitario'] ?? $producto->precio);
            $descuentoLinea = $this->aMoneda($linea['descuento'] ?? '0');
            $tasaEfectiva = $settings->aplica_itbis ? $producto->tasa_itbis : TasaItbis::CERO;

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
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'descuento' => $descuentoLinea,
                'tasa_itbis' => $tasaEfectiva,
                'itbis_monto' => $desglose->itbis,
                'subtotal' => $desglose->base,
            ];

            $productosLineas[] = ['producto' => $producto, 'cantidad' => $cantidad];
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
