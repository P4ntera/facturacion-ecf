<?php

namespace App\Services\Dgii;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\EcfInvalidoException;
use App\Models\DetalleVenta;
use App\Models\Venta;
use App\Settings\FacturacionSettings;

/**
 * Arma el JSON del e-CF que se envía al PAC a partir de una Venta ya registrada (montos y
 * desglose de ITBIS calculados por VentaService/ImpuestoStrategy — este builder solo mapea, no
 * recalcula nada). Solo incluye los campos que efectivamente tienen valor.
 */
class EcfBuilder
{
    /** @return array<string, mixed> */
    public function construir(Venta $venta): array
    {
        $venta->loadMissing(['detalles.producto', 'cliente']);

        $encabezado = [
            'Version' => '1.0',
            'IdDoc' => $this->idDoc($venta),
        ];

        $comprador = $this->comprador($venta);

        if ($comprador !== []) {
            $encabezado['Comprador'] = $comprador;
        }

        $encabezado['Totales'] = $this->totales($venta);

        // TODO (moneda extranjera): cuando venta.moneda !== 'DOP', agregar aquí
        // Encabezado.OtraMoneda { TipoMoneda, TipoCambio, MontoGravadoOtraMoneda1/2/3,
        // TotalITBISOtraMoneda, MontoTotalOtraMoneda } usando venta.tasa_cambio.

        return [
            'ECF' => [
                'Encabezado' => $encabezado,
                'DetallesItems' => [
                    'Item' => $venta->detalles->map(fn (DetalleVenta $detalle, int $indice) => $this->item($detalle, $indice))->all(),
                ],
                // TODO (propina legal, Ley 84-99): cuando aplique, agregar aquí
                // ImpuestosAdicionales con Codigo "001" y el monto correspondiente.
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function idDoc(Venta $venta): array
    {
        $idDoc = [
            'TipoeCF' => $venta->tipo_comprobante->value,
            'eNCF' => $venta->ncf,
        ];

        if (app(FacturacionSettings::class)->precio_incluye_itbis) {
            $idDoc['IndicadorServicioTodoIncluido'] = '1';
        }

        $idDoc['TipoIngresos'] = '01';
        $idDoc['TipoPago'] = (string) $venta->tipo_pago->value;

        if ($venta->tipo_pago === TipoPago::CREDITO) {
            if ($venta->fecha_limite_pago !== null) {
                $idDoc['FechaLimitePago'] = $venta->fecha_limite_pago->format('d-m-Y');
            }
        } else {
            // Contado: la forma de pago se conoce en el momento de la venta. A crédito no se
            // declara aquí (se conoce cuando se cobra, en un comprobante complementario futuro).
            $idDoc['TablaFormasPago'] = [
                'FormaDetalle' => [
                    ['FormaPago' => '1', 'MontoPago' => $this->monto($venta->total)],
                ],
            ];
        }

        return $idDoc;
    }

    /**
     * Reglas del PAC: Crédito Fiscal (31) siempre exige Comprador; Consumo (32) solo lo exige
     * desde Venta::UMBRAL_CONSUMO — por debajo, se omite el bloque aunque el cliente tenga RNC
     * (el PAC convierte el documento a RFCE). Los demás tipos (fuera del alcance de esta regla)
     * mantienen el comportamiento previo: se incluye si el cliente tiene documento.
     *
     * @return array<string, mixed>
     */
    private function comprador(Venta $venta): array
    {
        $cliente = $venta->cliente;
        $tieneRnc = ! blank($cliente->documento);

        if ($venta->requiereComprador() && ! $tieneRnc) {
            throw new EcfInvalidoException($this->mensajeRncFaltante($venta));
        }

        if ($venta->tipo_comprobante === TipoComprobante::FACTURA_CONSUMO && ! $venta->requiereComprador()) {
            return [];
        }

        if (! $tieneRnc) {
            return [];
        }

        return [
            'RNCComprador' => $cliente->documento,
            'RazonSocialComprador' => $cliente->nombre,
        ];
    }

    private function mensajeRncFaltante(Venta $venta): string
    {
        return match ($venta->tipo_comprobante) {
            TipoComprobante::FACTURA_CREDITO_FISCAL => "La venta #{$venta->id} es una Factura de Crédito Fiscal (e-CF 31) y el comprador no tiene RNC.",
            TipoComprobante::FACTURA_CONSUMO => "La venta #{$venta->id} es una Factura de Consumo (e-CF 32) de RD$".number_format((float) $venta->total, 2)
                .' y el comprador no tiene RNC (obligatorio desde RD$250,000.00).',
            default => "La venta #{$venta->id} requiere el RNC del comprador.",
        };
    }

    /** @return array<string, mixed> */
    private function totales(Venta $venta): array
    {
        $totales = [];

        if ($this->esPositivo($venta->monto_gravado_18)) {
            $totales['MontoGravadoI1'] = $this->monto($venta->monto_gravado_18);
            $totales['ITBIS1'] = '18';
            $totales['TotalITBIS1'] = $this->monto($venta->itbis_18);
        }

        if ($this->esPositivo($venta->monto_gravado_16)) {
            $totales['MontoGravadoI2'] = $this->monto($venta->monto_gravado_16);
            $totales['ITBIS2'] = '16';
            $totales['TotalITBIS2'] = $this->monto($venta->itbis_16);
        }

        if ($this->esPositivo($venta->monto_gravado_0)) {
            $totales['MontoGravadoI3'] = $this->monto($venta->monto_gravado_0);
            $totales['ITBIS3'] = '0';
        }

        $totales['MontoExento'] = $this->monto($venta->monto_exento);
        $totales['TotalITBIS'] = $this->monto($venta->total_itbis);
        $totales['MontoTotal'] = $this->monto($venta->total);

        return $totales;
    }

    /** @return array<string, mixed> */
    private function item(DetalleVenta $detalle, int $indice): array
    {
        $item = [
            'NumeroLinea' => (string) ($indice + 1),
            'IndicadorFacturacion' => match ($detalle->tasa_itbis) {
                TasaItbis::DIECIOCHO => '1',
                TasaItbis::DIECISEIS => '2',
                TasaItbis::CERO => '3',
            },
            'NombreItem' => $detalle->descripcion,
            'IndicadorBienoServicio' => $detalle->producto->tipo === TipoProducto::SERVICIO ? '2' : '1',
            'CantidadItem' => (string) $detalle->cantidad,
            'UnidadMedida' => $detalle->producto->tipo === TipoProducto::SERVICIO ? '1' : '43',
            'PrecioUnitarioItem' => $this->monto($detalle->precio_unitario),
            'MontoItem' => $this->monto($detalle->subtotal),
        ];

        if ($this->esPositivo($detalle->descuento)) {
            $item['DescuentoMonto'] = $this->monto($detalle->descuento);
            $item['TablaSubDescuento'] = [
                'SubDescuento' => [
                    ['TipoSubDescuento' => '$', 'MontoSubDescuento' => $this->monto($detalle->descuento)],
                ],
            ];
        }

        return $item;
    }

    private function monto(string $valor): string
    {
        return bcadd($valor, '0', 2);
    }

    private function esPositivo(string $valor): bool
    {
        return bccomp($valor, '0', 2) > 0;
    }
}
