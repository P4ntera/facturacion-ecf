<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListaPrecioTest extends TestCase
{
    use RefreshDatabase;

    private function secuenciaConsumo(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function producto(float $precio = 100): Producto
    {
        return Producto::create([
            'codigo' => 'LP-' . fake()->unique()->numerify('###'),
            'nombre' => 'Producto Test',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => $precio,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 1,
            'activo' => true,
        ]);
    }

    public function test_venta_usa_precio_de_lista_del_cliente(): void
    {
        $this->secuenciaConsumo();
        $producto = $this->producto(100);

        $lista = ListaPrecio::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Mayorista',
            'activa' => true,
        ]);

        $lista->productos()->attach($producto->id, ['precio' => 80.00]);

        $cliente = Cliente::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Cliente Mayorista',
            'lista_precio_id' => $lista->id,
            'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
        ], $this->empresaDefault);

        // Precio de lista (80) × 2 = 160 subtotal, no 200
        $detalle = $venta->detalles->first();
        $this->assertEqualsWithDelta(80.00, (float) $detalle->precio_unitario, 0.01);
        $this->assertEqualsWithDelta(160.00, (float) $venta->subtotal, 0.01);
    }

    public function test_precio_explicito_prevalece_sobre_lista(): void
    {
        $this->secuenciaConsumo();
        $producto = $this->producto(100);

        $lista = ListaPrecio::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Mayorista',
            'activa' => true,
        ]);

        $lista->productos()->attach($producto->id, ['precio' => 80.00]);

        $cliente = Cliente::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Cliente Mayorista',
            'lista_precio_id' => $lista->id,
            'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => 90]],
        ], $this->empresaDefault);

        // Precio explícito (90) prevalece sobre lista (80)
        $detalle = $venta->detalles->first();
        $this->assertEqualsWithDelta(90.00, (float) $detalle->precio_unitario, 0.01);
    }

    public function test_producto_sin_precio_en_lista_usa_precio_base(): void
    {
        $this->secuenciaConsumo();
        $producto = $this->producto(100);

        $lista = ListaPrecio::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Mayorista',
            'activa' => true,
        ]);

        // No asignamos precio a este producto en la lista

        $cliente = Cliente::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Cliente Mayorista',
            'lista_precio_id' => $lista->id,
            'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        // Usa precio base del producto (100) porque no está en la lista
        $detalle = $venta->detalles->first();
        $this->assertEqualsWithDelta(100.00, (float) $detalle->precio_unitario, 0.01);
    }

    public function test_lista_inactiva_no_aplica(): void
    {
        $this->secuenciaConsumo();
        $producto = $this->producto(100);

        $lista = ListaPrecio::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Mayorista',
            'activa' => false,
        ]);

        $lista->productos()->attach($producto->id, ['precio' => 80.00]);

        $cliente = Cliente::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Cliente Mayorista',
            'lista_precio_id' => $lista->id,
            'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        // Lista inactiva → precio base (100)
        $detalle = $venta->detalles->first();
        $this->assertEqualsWithDelta(100.00, (float) $detalle->precio_unitario, 0.01);
    }

    public function test_venta_sin_cliente_usa_precio_base(): void
    {
        $this->secuenciaConsumo();
        $producto = $this->producto(100);

        // Venta al portador (sin cliente)
        $venta = app(VentaService::class)->registrar([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $detalle = $venta->detalles->first();
        $this->assertEqualsWithDelta(100.00, (float) $detalle->precio_unitario, 0.01);
    }
}
