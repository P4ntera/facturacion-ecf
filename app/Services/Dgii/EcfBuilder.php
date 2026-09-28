<?php

namespace App\Services\Dgii;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\EcfInvalidoException;
use App\Models\DetalleVenta;
use App\Models\Venta;
use Illuminate\Support\Str;

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

        $this->validar($venta);

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
                ...$this->informacionReferencia($venta),
            ],
        ];
    }

    /**
     * Sección "InformacionReferencia" del formato e-CF (DGII): obligatoria en Notas de Crédito
     * (34) y Débito (33), que siempre modifican otro e-NCF. Va al final del ECF, no dentro de
     * IdDoc.
     *
     * CodigoModificacion (catálogo DGII): 1 = anula el NCF modificado (la Nota de Crédito que
     * emite VentaService::anular(), marcada con venta_modificada_id); 3 = corrige montos (una
     * nota registrada a mano con ncf_modifica, que ajusta la original sin anularla).
     *
     * @return array<string, mixed>
     */
    private function informacionReferencia(Venta $venta): array
    {
        if (blank($venta->ncf_modifica)) {
            return [];
        }

        $original = $this->ventaModificada($venta);
        $esAnulacion = $venta->esNotaCreditoDeAnulacion();

        $referencia = ['NCFModificado' => $venta->ncf_modifica];

        if ($original !== null) {
            $referencia['FechaNCFModificado'] = $original->fecha->format('d-m-Y');
        }

        $referencia['CodigoModificacion'] = $esAnulacion ? '1' : '3';

        $razon = $esAnulacion ? $original?->motivo_anulacion : null;

        if (filled($razon)) {
            $referencia['RazonModificacion'] = Str::limit($razon, 87);
        }

        return ['InformacionReferencia' => $referencia];
    }

    private function ventaModificada(Venta $venta): ?Venta
    {
        if ($venta->esNotaCreditoDeAnulacion()) {
            return $venta->ventaModificada;
        }

        return Venta::query()
            ->where('empresa_id', $venta->empresa_id)
            ->where('ncf', $venta->ncf_modifica)
            ->first();
    }

    /**
     * Última barrera antes del PAC: un e-CF aceptado no se puede retirar, así que lo que no
     * cuadre aquí se rechaza localmente (EnvioEcfService lo deja RECHAZADO con el motivo, sin
     * gastar un envío). Normalmente VentaService ya lo impide; esto protege de datos corruptos,
     * cambios manuales en BD o bugs futuros de cálculo.
     *
     * @throws EcfInvalidoException
     */
    private function validar(Venta $venta): void
    {
        $tipo = $venta->tipo_comprobante;

        if ($tipo === null || ! $tipo->esElectronico()) {
            throw new EcfInvalidoException("La venta #{$venta->id} no tiene un comprobante electrónico: no se envía a la DGII.");
        }

        if (! preg_match('/^E'.preg_quote($tipo->value, '/').'\d{10}$/', (string) $venta->ncf)) {
            throw new EcfInvalidoException("El e-NCF «{$venta->ncf}» de la venta #{$venta->id} no tiene el formato de un e-CF tipo {$tipo->value} (E{$tipo->value} + 10 dígitos).");
        }

        if (in_array($tipo, [TipoComprobante::NOTA_CREDITO, TipoComprobante::NOTA_DEBITO], true) && blank($venta->ncf_modifica)) {
            throw new EcfInvalidoException("La nota #{$venta->id} ({$venta->ncf}) no indica el e-NCF que modifica.");
        }

        if ($venta->detalles->isEmpty()) {
            throw new EcfInvalidoException("La venta #{$venta->id} no tiene líneas.");
        }

        $montos = [$venta->total, $venta->total_itbis, $venta->monto_gravado_18, $venta->monto_gravado_16, $venta->monto_gravado_0, $venta->monto_exento];

        foreach ($venta->detalles as $detalle) {
            array_push($montos, $detalle->subtotal, $detalle->itbis_monto, $detalle->precio_unitario, $detalle->descuento);
        }

        foreach ($montos as $monto) {
            if (bccomp((string) $monto, '0', 2) < 0) {
                throw new EcfInvalidoException("La venta #{$venta->id} tiene montos negativos: un e-CF no los admite (las Notas de Crédito van en positivo).");
            }
        }

        // La DGII valida que MontoTotal = gravados + exento + ITBIS, y que las líneas sumen los
        // gravados. Si no cuadra, lo rechazaría: mejor detenerlo aquí con el motivo exacto.
        $base = bcadd(bcadd(bcadd((string) $venta->monto_gravado_18, (string) $venta->monto_gravado_16, 2), (string) $venta->monto_gravado_0, 2), (string) $venta->monto_exento, 2);
        $totalEsperado = bcadd($base, (string) $venta->total_itbis, 2);

        if (bccomp($totalEsperado, (string) $venta->total, 2) !== 0) {
            throw new EcfInvalidoException("Los totales de la venta #{$venta->id} no cuadran: gravado + exento + ITBIS = {$totalEsperado}, pero el total es {$venta->total}.");
        }

        $sumaLineas = $venta->detalles->reduce(fn (string $suma, DetalleVenta $detalle) => bcadd($suma, (string) $detalle->subtotal, 2), '0.00');

        if (bccomp($sumaLineas, $base, 2) !== 0) {
            throw new EcfInvalidoException("Las líneas de la venta #{$venta->id} suman {$sumaLineas}, pero la base gravada + exenta es {$base}.");
        }
    }

    /** @return array<string, mixed> */
    private function idDoc(Venta $venta): array
    {
        $idDoc = [
            'TipoeCF' => $venta->tipo_comprobante->value,
            'eNCF' => $venta->ncf,
        ];

        // Nota de Crédito (34): IndicadorNotaCredito = 1 si se emite más de 30 días después del
        // e-CF que modifica (la DGII no permite entonces rebajar el ITBIS). El e-NCF modificado va
        // en InformacionReferencia, no aquí.
        if ($venta->tipo_comprobante === TipoComprobante::NOTA_CREDITO) {
            $original = $this->ventaModificada($venta);
            $idDoc['IndicadorNotaCredito'] = $original !== null && $original->fecha->diffInDays($venta->fecha) > 30 ? '1' : '0';
        }

        if ($venta->empresa->config()->precio_incluye_itbis) {
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
        // Null = venta al portador (solo posible donde el comprobante no exige comprador).
        $cliente = $venta->cliente;
        $tieneRnc = ! blank($cliente?->documento);

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
