<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\EcfInvalidoException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\Venta;
use App\Services\Dgii\EcfBuilder;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcfBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function secuencia(TipoComprobante $tipo, string $prefijo): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => $tipo,
            'prefijo' => $prefijo,
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function producto(string $codigo, TasaItbis $tasa, TipoProducto $tipo = TipoProducto::PRODUCTO, array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'tipo' => $tipo,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => $tasa,
            'controla_stock' => $tipo === TipoProducto::PRODUCTO,
            'stock' => 100,
            'stock_minimo' => 1,
            'activo' => true,
        ], $overrides));
    }

    public function test_incluye_solo_los_brackets_de_itbis_con_montos(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $productoDieciocho = $this->producto('P18', TasaItbis::DIECIOCHO);
        $productoCero = $this->producto('P0', TasaItbis::CERO);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [
                ['producto_id' => $productoDieciocho->id, 'cantidad' => 1],
                ['producto_id' => $productoCero->id, 'cantidad' => 1],
            ],
        ], $this->empresaDefault)->refresh();

        $ecf = app(EcfBuilder::class)->construir($venta);
        $totales = $ecf['ECF']['Encabezado']['Totales'];

        $this->assertSame('100.00', $totales['MontoGravadoI1']);
        $this->assertSame('18', $totales['ITBIS1']);
        $this->assertSame('18.00', $totales['TotalITBIS1']);

        $this->assertArrayNotHasKey('MontoGravadoI2', $totales);
        $this->assertArrayNotHasKey('ITBIS2', $totales);
        $this->assertArrayNotHasKey('TotalITBIS2', $totales);

        $this->assertSame('100.00', $totales['MontoGravadoI3']);
        $this->assertSame('0', $totales['ITBIS3']);
        $this->assertArrayNotHasKey('TotalITBIS3', $totales);

        $this->assertSame('18.00', $totales['TotalITBIS']);
        $this->assertSame('218.00', $totales['MontoTotal']);
    }

    public function test_descuento_de_linea_agrega_tabla_de_subdescuento(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PDESC', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'descuento' => '10.00'],
            ],
        ], $this->empresaDefault)->refresh();

        $item = app(EcfBuilder::class)->construir($venta)['ECF']['DetallesItems']['Item'][0];

        $this->assertSame('10.00', $item['DescuentoMonto']);
        $this->assertSame('$', $item['TablaSubDescuento']['SubDescuento'][0]['TipoSubDescuento']);
        $this->assertSame('10.00', $item['TablaSubDescuento']['SubDescuento'][0]['MontoSubDescuento']);
    }

    public function test_servicio_usa_indicador_y_unidad_de_medida_distintos_a_un_bien(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $bien = $this->producto('BIEN', TasaItbis::DIECIOCHO, TipoProducto::PRODUCTO);
        $servicio = $this->producto('SERV', TasaItbis::DIECIOCHO, TipoProducto::SERVICIO);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [
                ['producto_id' => $bien->id, 'cantidad' => 1],
                ['producto_id' => $servicio->id, 'cantidad' => 1],
            ],
        ], $this->empresaDefault)->refresh();

        $items = app(EcfBuilder::class)->construir($venta)['ECF']['DetallesItems']['Item'];

        $this->assertSame('1', $items[0]['IndicadorBienoServicio']);
        $this->assertSame('43', $items[0]['UnidadMedida']);
        $this->assertSame('2', $items[1]['IndicadorBienoServicio']);
        $this->assertSame('1', $items[1]['UnidadMedida']);
    }

    public function test_contado_agrega_tabla_de_formas_de_pago_con_el_total(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PCONT', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $idDoc = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['IdDoc'];

        $this->assertSame('1', $idDoc['TipoPago']);
        $this->assertSame('1', $idDoc['TablaFormasPago']['FormaDetalle'][0]['FormaPago']);
        $this->assertSame((string) $venta->total, $idDoc['TablaFormasPago']['FormaDetalle'][0]['MontoPago']);
        $this->assertArrayNotHasKey('FechaLimitePago', $idDoc);
    }

    public function test_credito_agrega_fecha_limite_de_pago_y_omite_la_forma_de_pago(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PCRED', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $venta->update(['tipo_pago' => TipoPago::CREDITO, 'fecha_limite_pago' => '2026-08-01']);

        $idDoc = app(EcfBuilder::class)->construir($venta->refresh())['ECF']['Encabezado']['IdDoc'];

        $this->assertSame('2', $idDoc['TipoPago']);
        $this->assertSame('01-08-2026', $idDoc['FechaLimitePago']);
        $this->assertArrayNotHasKey('TablaFormasPago', $idDoc);
    }

    public function test_factura_credito_fiscal_exige_rnc_del_comprador(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');

        $producto = $this->producto('PFC', TasaItbis::DIECIOCHO);
        // VentaService::registrar() ya exige RNC para el 31 al cobrar; se crea con uno válido y
        // se le quita después, para probar la defensa "en profundidad" del builder.
        $cliente = Cliente::create(['nombre' => 'Con RNC luego retirado', 'documento' => '130123456', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $venta->cliente->update(['documento' => null]);

        $this->expectException(EcfInvalidoException::class);

        app(EcfBuilder::class)->construir($venta);
    }

    public function test_factura_credito_fiscal_incluye_comprador_con_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');

        $producto = $this->producto('PFC2', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Comercial ABC SRL', 'documento' => '130123456', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $comprador = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['Comprador'];

        $this->assertSame('130123456', $comprador['RNCComprador']);
        $this->assertSame('Comercial ABC SRL', $comprador['RazonSocialComprador']);
    }

    public function test_consumidor_final_sin_rnc_omite_el_bloque_comprador_en_consumo(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PCF', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $encabezado = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado'];

        $this->assertArrayNotHasKey('Comprador', $encabezado);
    }

    public function test_consumo_por_debajo_del_umbral_omite_comprador_aunque_el_cliente_tenga_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PCF-RNC', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Comercial Con RNC SRL', 'documento' => '130123456', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $this->assertTrue(bccomp((string) $venta->total, Venta::UMBRAL_CONSUMO, 2) < 0);

        $encabezado = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado'];

        $this->assertArrayNotHasKey('Comprador', $encabezado);
    }

    public function test_consumo_en_o_por_encima_del_umbral_exige_rnc_del_comprador(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PCF-250K', TasaItbis::DIECIOCHO, overrides: ['precio' => 300000]);
        // VentaService::registrar() ya exige RNC en consumo >= 250k al cobrar; se crea con uno
        // válido y se le quita después, para probar la defensa "en profundidad" del builder.
        $cliente = Cliente::create(['nombre' => 'Con RNC luego retirado', 'documento' => '130555555', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $this->assertTrue(bccomp((string) $venta->total, Venta::UMBRAL_CONSUMO, 2) >= 0);

        $venta->cliente->update(['documento' => null]);

        $this->expectException(EcfInvalidoException::class);

        app(EcfBuilder::class)->construir($venta);
    }

    public function test_consumo_en_o_por_encima_del_umbral_incluye_comprador_con_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PCF-250K-OK', TasaItbis::DIECIOCHO, overrides: ['precio' => 300000]);
        $cliente = Cliente::create(['nombre' => 'Comercial Grande SRL', 'documento' => '130987654', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $this->assertTrue(bccomp((string) $venta->total, Venta::UMBRAL_CONSUMO, 2) >= 0);

        $comprador = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['Comprador'];

        $this->assertSame('130987654', $comprador['RNCComprador']);
        $this->assertSame('Comercial Grande SRL', $comprador['RazonSocialComprador']);
    }

    /**
     * Una Nota de Crédito registrada a mano (ajuste de montos) declara el e-NCF modificado en
     * InformacionReferencia (formato e-CF DGII), con CodigoModificacion 3, y lleva
     * IndicadorNotaCredito en IdDoc.
     */
    public function test_nota_de_credito_incluye_informacion_de_referencia(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $producto = $this->producto('PCF-NC', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Cliente NC', 'activo' => true]);

        $ventaOriginal = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $notaCredito = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO->value,
            'ncf_modifica' => $ventaOriginal->ncf,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $ecf = app(EcfBuilder::class)->construir($notaCredito)['ECF'];

        $this->assertArrayNotHasKey('NCFModificado', $ecf['Encabezado']['IdDoc']);
        $this->assertSame('0', $ecf['Encabezado']['IdDoc']['IndicadorNotaCredito']);
        $this->assertSame([
            'NCFModificado' => $ventaOriginal->ncf,
            'FechaNCFModificado' => $ventaOriginal->fecha->format('d-m-Y'),
            'CodigoModificacion' => '3',
        ], $ecf['InformacionReferencia']);
    }

    public function test_e31_incluye_fecha_vencimiento_secuencia(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');

        $producto = $this->producto('P-FV31', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Empresa SRL', 'documento' => '130111222', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $idDoc = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['IdDoc'];

        $secuencia = SecuenciaNcf::where('tipo_comprobante', TipoComprobante::FACTURA_CREDITO_FISCAL)->first();
        $this->assertSame($secuencia->vencimiento->format('d-m-Y'), $idDoc['FechaVencimientoSecuencia']);
    }

    public function test_e32_no_incluye_fecha_vencimiento_secuencia(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('P-FV32', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $idDoc = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['IdDoc'];

        $this->assertArrayNotHasKey('FechaVencimientoSecuencia', $idDoc);
    }

    public function test_e34_no_incluye_fecha_vencimiento_secuencia(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $producto = $this->producto('P-FV34', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Cliente NC FV', 'activo' => true]);

        $ventaOriginal = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $notaCredito = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO->value,
            'ncf_modifica' => $ventaOriginal->ncf,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $idDoc = app(EcfBuilder::class)->construir($notaCredito)['ECF']['Encabezado']['IdDoc'];

        $this->assertArrayNotHasKey('FechaVencimientoSecuencia', $idDoc);
    }

    public function test_rango_sin_vencimiento_rechaza(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL,
            'prefijo' => 'E31',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => null,
            'activa' => true,
        ]);

        $producto = $this->producto('P-FVSV', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Empresa SRL', 'documento' => '130111333', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $this->expectException(EcfInvalidoException::class);

        app(EcfBuilder::class)->construir($venta);
    }

    /** Una venta normal (sin ncf_modifica) no debe llevar NCFModificado en el XML/JSON. */
    public function test_venta_normal_no_incluye_ncf_modificado(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('PCF-SIN-NC', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $ecf = app(EcfBuilder::class)->construir($venta)['ECF'];

        $this->assertArrayNotHasKey('NCFModificado', $ecf['Encabezado']['IdDoc']);
        $this->assertArrayNotHasKey('IndicadorNotaCredito', $ecf['Encabezado']['IdDoc']);
        $this->assertArrayNotHasKey('InformacionReferencia', $ecf);
    }

    // ===================== FORMAS DE PAGO REALES =====================

    public function test_solo_efectivo_usa_codigo_1(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('P-EF', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'forma_pago' => FormaPago::EFECTIVO,
            'monto_efectivo' => '118.00',
            'vuelto' => '0.00',
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $formas = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['IdDoc']['TablaFormasPago']['FormaDetalle'];

        $this->assertCount(1, $formas);
        $this->assertSame('1', $formas[0]['FormaPago']);
        $this->assertSame('118.00', $formas[0]['MontoPago']);
    }

    public function test_solo_tarjeta_usa_codigo_3(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('P-TAR', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'forma_pago' => FormaPago::TARJETA,
            'monto_tarjeta' => '118.00',
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $formas = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['IdDoc']['TablaFormasPago']['FormaDetalle'];

        $this->assertCount(1, $formas);
        $this->assertSame('3', $formas[0]['FormaPago']);
        $this->assertSame('118.00', $formas[0]['MontoPago']);
    }

    public function test_mixto_efectivo_y_tarjeta_genera_dos_entradas(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('P-MIX', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'forma_pago' => FormaPago::MIXTO,
            'monto_efectivo' => '50.00',
            'monto_tarjeta' => '68.00',
            'vuelto' => '0.00',
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $formas = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['IdDoc']['TablaFormasPago']['FormaDetalle'];

        $this->assertCount(2, $formas);
        $this->assertSame('1', $formas[0]['FormaPago']);
        $this->assertSame('50.00', $formas[0]['MontoPago']);
        $this->assertSame('3', $formas[1]['FormaPago']);
        $this->assertSame('68.00', $formas[1]['MontoPago']);
    }

    public function test_efectivo_con_vuelto_declara_neto(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('P-VTO', TasaItbis::DIECIOCHO);
        $cliente = Cliente::create(['nombre' => 'Consumidor', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'forma_pago' => FormaPago::EFECTIVO,
            'monto_efectivo' => '200.00',
            'vuelto' => '82.00',
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $formas = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado']['IdDoc']['TablaFormasPago']['FormaDetalle'];

        $this->assertCount(1, $formas);
        $this->assertSame('1', $formas[0]['FormaPago']);
        $this->assertSame('118.00', $formas[0]['MontoPago']);
    }
}
