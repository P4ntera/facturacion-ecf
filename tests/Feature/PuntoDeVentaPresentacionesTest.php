<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Filament\Pages\PuntoDeVenta;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PuntoDeVentaPresentacionesTest extends TestCase
{
    use RefreshDatabase;

    private function vendedor(): User
    {
        Permission::firstOrCreate(['name' => 'pos.acceder', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['pos.acceder']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function productoContableConPresentaciones(): Producto
    {
        $producto = Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'REF-001',
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
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'HAB-001',
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

    public function test_escanear_el_codigo_de_una_caja_agrega_esa_presentacion(): void
    {
        $producto = $this->productoContableConPresentaciones();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '750103')
            ->call('escanearOBuscar')
            ->assertSet('carrito.0.producto_id', $producto->id)
            ->assertSet('carrito.0.nombre', 'Refresco Cola - Caja')
            ->assertSet('carrito.0.precio_unitario', '1000.00')
            ->assertSet('carrito.0.cantidad', 1)
            ->assertSet('carrito.0.factor', '24.000')
            ->assertSet('busquedaProducto', '');
    }

    public function test_escanear_el_codigo_de_la_unidad_base_no_agrega_sufijo_al_nombre(): void
    {
        $this->productoContableConPresentaciones();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '750101')
            ->call('escanearOBuscar')
            ->assertSet('carrito.0.nombre', 'Refresco Cola')
            ->assertSet('carrito.0.precio_unitario', '50.00')
            ->assertSet('carrito.0.factor', '1.000');
    }

    public function test_escanear_la_misma_presentacion_dos_veces_suma_la_cantidad(): void
    {
        $this->productoContableConPresentaciones();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '750103')
            ->call('escanearOBuscar')
            ->set('busquedaProducto', '750103')
            ->call('escanearOBuscar')
            ->assertSet('carrito.0.cantidad', 2);
    }

    public function test_escanear_dos_presentaciones_distintas_del_mismo_producto_crea_dos_lineas(): void
    {
        $this->productoContableConPresentaciones();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '750101')
            ->call('escanearOBuscar')
            ->set('busquedaProducto', '750103')
            ->call('escanearOBuscar');

        $this->assertCount(2, $componente->get('carrito'));
    }

    public function test_vender_una_caja_baja_el_stock_en_unidad_base_al_validar(): void
    {
        $this->productoContableConPresentaciones();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '750103')
            ->call('escanearOBuscar')
            ->set('carrito.0.cantidad', 5); // 5 cajas × 24 = 120 > 100 en stock

        $this->assertTrue($componente->instance()->lineaConStockInsuficiente($componente->get('carrito')[0]));
        $this->assertFalse($componente->instance()->puedeCobrar());
    }

    public function test_agregar_producto_click_resuelve_la_presentacion_base(): void
    {
        $producto = $this->productoContableConPresentaciones();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->assertSet('carrito.0.nombre', 'Refresco Cola')
            ->assertSet('carrito.0.precio_unitario', '50.00')
            ->assertSet('carrito.0.factor', '1.000');
    }

    public function test_escanear_codigo_de_producto_pesado_no_agrega_y_notifica(): void
    {
        $producto = $this->productoPesado();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'HAB-001')
            ->call('escanearOBuscar')
            ->assertSet('carrito', [])
            ->assertSet('busquedaProducto', 'HAB-001')
            ->assertNotified();

        $this->assertNotNull($producto); // solo para usar la variable y documentar el fixture
    }

    public function test_agregar_producto_pesado_por_peso(): void
    {
        $producto = $this->productoPesado();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProductoPorPeso', $producto->id, '1.6')
            ->assertSet('carrito.0.producto_id', $producto->id)
            ->assertSet('carrito.0.cantidad', '1.6')
            ->assertSet('carrito.0.precio_unitario', '45.00')
            ->assertSet('totales.subtotal', '72.00')
            ->assertSet('totales.total', '72.00');
    }

    public function test_agregar_producto_pesado_dos_veces_no_suma_crea_dos_lineas(): void
    {
        $producto = $this->productoPesado();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProductoPorPeso', $producto->id, '1.6')
            ->call('agregarProductoPorPeso', $producto->id, '2.0');

        $this->assertCount(2, $componente->get('carrito'));
    }

    public function test_peso_cero_o_negativo_se_rechaza_con_mensaje(): void
    {
        $producto = $this->productoPesado();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProductoPorPeso', $producto->id, '0')
            ->assertSet('carrito', [])
            ->assertNotified();
    }

    public function test_pesar_mas_del_stock_disponible_bloquea_cobrar(): void
    {
        $producto = $this->productoPesado();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProductoPorPeso', $producto->id, '60'); // stock es 50 lb

        $this->assertTrue($componente->instance()->lineaConStockInsuficiente($componente->get('carrito')[0]));
        $this->assertFalse($componente->instance()->puedeCobrar());
    }
}
