<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoProveedorTest extends TestCase
{
    use RefreshDatabase;

    private function crearProducto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo'         => 'P-' . fake()->unique()->numerify('###'),
            'nombre'         => 'Producto Test',
            'tipo'           => TipoProducto::PRODUCTO->value,
            'costo'          => 50,
            'precio'         => 100,
            'tasa_itbis'     => TasaItbis::DIECIOCHO->value,
            'controla_stock' => true,
            'stock'          => 0,
            'stock_minimo'   => 0,
            'activo'         => true,
        ], $overrides));
    }

    public function test_producto_puede_tener_multiples_proveedores(): void
    {
        $producto    = $this->crearProducto();
        $proveedorA  = Proveedor::factory()->create();
        $proveedorB  = Proveedor::factory()->create();

        $producto->proveedores()->attach($proveedorA->id, ['es_principal' => false]);
        $producto->proveedores()->attach($proveedorB->id, ['es_principal' => false]);

        $this->assertCount(2, $producto->proveedores()->get());
        $this->assertCount(1, $proveedorA->productos()->get());
    }

    public function test_solo_un_proveedor_puede_ser_principal_por_producto(): void
    {
        $producto   = $this->crearProducto();
        $proveedorA = Proveedor::factory()->create();
        $proveedorB = Proveedor::factory()->create();

        $producto->proveedores()->attach($proveedorA->id, ['es_principal' => true]);
        $producto->proveedores()->attach($proveedorB->id, ['es_principal' => true]);

        $this->assertDatabaseHas('producto_proveedor', [
            'producto_id'  => $producto->id,
            'proveedor_id' => $proveedorA->id,
            'es_principal' => false,
        ]);
        $this->assertDatabaseHas('producto_proveedor', [
            'producto_id'  => $producto->id,
            'proveedor_id' => $proveedorB->id,
            'es_principal' => true,
        ]);

        $this->assertEquals($proveedorB->id, $producto->proveedorPrincipal()?->id);
    }

    public function test_editar_pivot_a_principal_apaga_el_anterior(): void
    {
        $producto   = $this->crearProducto();
        $proveedorA = Proveedor::factory()->create();
        $proveedorB = Proveedor::factory()->create();

        $producto->proveedores()->attach($proveedorA->id, ['es_principal' => true]);
        $producto->proveedores()->attach($proveedorB->id, ['es_principal' => false]);

        $this->assertEquals($proveedorA->id, $producto->proveedorPrincipal()?->id);

        $producto->proveedores()->updateExistingPivot($proveedorB->id, ['es_principal' => true]);

        $this->assertDatabaseHas('producto_proveedor', [
            'producto_id'  => $producto->id,
            'proveedor_id' => $proveedorA->id,
            'es_principal' => false,
        ]);
        $this->assertEquals($proveedorB->id, $producto->proveedorPrincipal()?->id);
    }
}
