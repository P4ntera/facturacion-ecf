<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Services\Dgii\EcfBuilder;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcfBuilderPresentacionesTest extends TestCase
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

    public function test_item_de_una_presentacion_reporta_su_nombre_cantidad_y_precio(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = Producto::create([
            'codigo' => 'ECF-REF',
            'nombre' => 'Refresco Cola',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::CONTABLE,
            'costo' => 30,
            'precio' => 50,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $caja = $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Caja',
            'factor' => 24,
            'codigo_barra' => '750103',
            'precio' => 1000,
            'es_base' => false,
            'activa' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'presentacion_id' => $caja->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();

        $item = app(EcfBuilder::class)->construir($venta)['ECF']['DetallesItems']['Item'][0];

        $this->assertSame('Refresco Cola - Caja', $item['NombreItem']);
        $this->assertSame('1.000', $item['CantidadItem']);
        $this->assertSame('1000.00', $item['PrecioUnitarioItem']);
        $this->assertSame('1000.00', $item['MontoItem']);
        $this->assertSame('43', $item['UnidadMedida']);
        $this->assertSame('1', $item['IndicadorBienoServicio']);
    }

    public function test_item_de_un_producto_pesado_reporta_el_peso_y_el_precio_por_peso(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = Producto::create([
            'codigo' => 'ECF-HAB',
            'nombre' => 'Habichuelas',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'libra',
            'precio_por_peso' => 45,
            'costo' => 20,
            'precio' => 0,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 50,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1.6, 'precio_unitario' => 45]],
        ], $this->empresaDefault)->refresh();

        $item = app(EcfBuilder::class)->construir($venta)['ECF']['DetallesItems']['Item'][0];

        $this->assertSame('Habichuelas', $item['NombreItem']);
        $this->assertSame('1.600', $item['CantidadItem']);
        $this->assertSame('45.00', $item['PrecioUnitarioItem']);
        $this->assertSame('72.00', $item['MontoItem']);
        // El peso sigue reportándose como unidad de medida 43 (no hay código DGII dedicado para
        // "libra"/"kg" en este catálogo): el desglose de ITBIS y totales tampoco cambian.
        $this->assertSame('43', $item['UnidadMedida']);
    }
}
