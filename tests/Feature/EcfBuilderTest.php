<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\EcfInvalidoException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
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

    private function producto(string $codigo, TasaItbis $tasa, TipoProducto $tipo = TipoProducto::PRODUCTO): Producto
    {
        return Producto::create([
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
        ]);
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
        ])->refresh();

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
        ])->refresh();

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
        ])->refresh();

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
        ])->refresh();

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
        ])->refresh();

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
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->refresh();

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
        ])->refresh();

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
        ])->refresh();

        $encabezado = app(EcfBuilder::class)->construir($venta)['ECF']['Encabezado'];

        $this->assertArrayNotHasKey('Comprador', $encabezado);
    }
}
