<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\VentaInvalidaException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaPresentacionesTest extends TestCase
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

    private function cliente(): Cliente
    {
        return Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);
    }

    private function productoConPresentaciones(): Producto
    {
        $producto = Producto::create([
            'codigo' => 'VP-REF',
            'nombre' => 'Refresco Cola',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::CONTABLE,
            'costo' => 30,
            'precio' => 50,
            'tasa_itbis' => TasaItbis::CERO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Unidad',
            'factor' => 1,
            'codigo_barra' => '750101',
            'precio' => 50,
            'es_base' => true,
            'activa' => true,
        ]);

        $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Caja',
            'factor' => 24,
            'codigo_barra' => '750103',
            'precio' => 1000,
            'es_base' => false,
            'activa' => true,
        ]);

        return $producto;
    }

    private function productoPesado(): Producto
    {
        return Producto::create([
            'codigo' => 'VP-HAB',
            'nombre' => 'Habichuelas',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'libra',
            'precio_por_peso' => 45,
            'costo' => 20,
            'precio' => 0,
            'tasa_itbis' => TasaItbis::CERO,
            'controla_stock' => true,
            'stock' => 50,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    public function test_registrar_venta_de_una_caja_descuenta_stock_en_unidad_base(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->productoConPresentaciones();
        $caja = $producto->presentaciones()->where('nombre', 'Caja')->first();

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'presentacion_id' => $caja->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $detalle = $venta->detalles->first();
        $this->assertSame($caja->id, $detalle->presentacion_id);
        $this->assertSame('Refresco Cola - Caja', $detalle->descripcion);
        $this->assertSame('24.000', (string) $detalle->factor);
        $this->assertSame('1000.00', (string) $detalle->precio_unitario);
        $this->assertSame('1000.00', (string) $detalle->subtotal);

        $this->assertSame('76.000', (string) $producto->refresh()->stock);
    }

    public function test_registrar_venta_de_la_presentacion_base_no_agrega_sufijo_al_nombre(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->productoConPresentaciones();
        $unidad = $producto->presentaciones()->where('nombre', 'Unidad')->first();

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'presentacion_id' => $unidad->id, 'cantidad' => 3]],
        ], $this->empresaDefault);

        $this->assertSame('Refresco Cola', $venta->detalles->first()->descripcion);
        $this->assertSame('97.000', (string) $producto->refresh()->stock);
    }

    public function test_anular_venta_con_presentacion_repone_el_stock_en_unidad_base(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->productoConPresentaciones();
        $caja = $producto->presentaciones()->where('nombre', 'Caja')->first();

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'presentacion_id' => $caja->id, 'cantidad' => 2]],
        ], $this->empresaDefault);

        $this->assertSame('52.000', (string) $producto->refresh()->stock); // 100 - 2×24

        app(VentaService::class)->anular($venta, 'prueba');

        $this->assertSame('100.000', (string) $producto->refresh()->stock);
    }

    public function test_venta_por_peso_descuenta_el_peso_exacto_del_stock(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->productoPesado();

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1.6, 'precio_unitario' => 45]],
        ], $this->empresaDefault);

        $detalle = $venta->detalles->first();
        $this->assertNull($detalle->presentacion_id);
        $this->assertSame('1.000', (string) $detalle->factor);
        $this->assertSame('72.00', (string) $detalle->subtotal);

        $this->assertSame('48.400', (string) $producto->refresh()->stock);
    }

    public function test_anular_venta_por_peso_repone_el_peso_exacto(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->productoPesado();

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1.6, 'precio_unitario' => 45]],
        ], $this->empresaDefault);

        app(VentaService::class)->anular($venta, 'prueba');

        $this->assertSame('50.000', (string) $producto->refresh()->stock);
    }

    public function test_presentacion_de_otro_producto_se_rechaza(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $productoA = $this->productoConPresentaciones();
        $cajaDeA = $productoA->presentaciones()->where('nombre', 'Caja')->first();

        $productoB = Producto::create([
            'codigo' => 'VP-OTRO',
            'nombre' => 'Otro producto',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::CONTABLE,
            'costo' => 5,
            'precio' => 10,
            'tasa_itbis' => TasaItbis::CERO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $productoB->id, 'presentacion_id' => $cajaDeA->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    public function test_vender_mas_cajas_de_las_que_hay_en_stock_bloquea_con_mensaje_en_espanol(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->productoConPresentaciones(); // stock 100, caja factor 24
        $caja = $producto->presentaciones()->where('nombre', 'Caja')->first();

        $this->expectException(StockInsuficienteException::class);
        $this->expectExceptionMessage('Stock insuficiente');

        app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            // 5 cajas × 24 = 120, por encima de las 100 unidades base disponibles.
            'lineas' => [['producto_id' => $producto->id, 'presentacion_id' => $caja->id, 'cantidad' => 5]],
        ], $this->empresaDefault);
    }

    public function test_pesar_mas_de_lo_que_hay_en_stock_bloquea_con_mensaje_en_espanol(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->productoPesado(); // stock 50 lb

        $this->expectException(StockInsuficienteException::class);
        $this->expectExceptionMessage('Stock insuficiente');

        app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 60, 'precio_unitario' => 45]],
        ], $this->empresaDefault);
    }

    public function test_producto_sin_presentaciones_sigue_funcionando_como_antes(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = Producto::create([
            'codigo' => 'VP-LEGACY',
            'nombre' => 'Producto sin presentaciones',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 5,
            'precio' => 20,
            'tasa_itbis' => TasaItbis::CERO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
        ], $this->empresaDefault);

        $detalle = $venta->detalles->first();
        $this->assertNull($detalle->presentacion_id);
        $this->assertSame('1.000', (string) $detalle->factor);
        $this->assertSame('8.000', (string) $producto->refresh()->stock);
    }
}
