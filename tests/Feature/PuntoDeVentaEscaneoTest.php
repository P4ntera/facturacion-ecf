<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Pages\PuntoDeVenta;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PuntoDeVentaEscaneoTest extends TestCase
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

    private function producto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo' => 'ESC-'.fake()->unique()->numerify('###'),
            'nombre' => 'Producto escaneado',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 5,
            'stock_minimo' => 1,
            'activo' => true,
        ], $overrides));
    }

    public function test_escanear_un_codigo_de_barras_exacto_agrega_el_producto_y_limpia_el_campo(): void
    {
        $producto = $this->producto(['codigo_barra' => '7501234567890']);

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '7501234567890')
            ->call('escanearOBuscar')
            ->assertSet('carrito.0.producto_id', $producto->id)
            ->assertSet('carrito.0.cantidad', 1)
            ->assertSet('busquedaProducto', '')
            ->assertDispatched('producto-escaneado');
    }

    public function test_escanear_el_mismo_codigo_dos_veces_suma_la_cantidad(): void
    {
        $producto = $this->producto(['codigo_barra' => '7501234567890']);

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '7501234567890')
            ->call('escanearOBuscar')
            ->set('busquedaProducto', '7501234567890')
            ->call('escanearOBuscar')
            ->assertSet('carrito.0.cantidad', 2);
    }

    public function test_escanear_por_el_codigo_del_producto_tambien_funciona(): void
    {
        $producto = $this->producto(['codigo' => 'ESC-999', 'codigo_barra' => null]);

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'ESC-999')
            ->call('escanearOBuscar')
            ->assertSet('carrito.0.producto_id', $producto->id);
    }

    public function test_texto_sin_coincidencia_exacta_no_agrega_nada_y_deja_la_busqueda_normal(): void
    {
        $this->producto(['codigo' => 'ESC-PARCIAL', 'nombre' => 'Producto parcial']);

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'ESC-PAR') // coincidencia parcial, no exacta
            ->call('escanearOBuscar');

        $componente->assertSet('carrito', []);
        $componente->assertSet('busquedaProducto', 'ESC-PAR'); // no se limpia: no hubo escaneo
        $this->assertCount(1, $componente->instance()->productosSugeridos()); // la búsqueda normal sigue funcionando
    }

    public function test_producto_inactivo_no_se_agrega_y_notifica(): void
    {
        $this->producto(['codigo_barra' => '7501234567890', 'activo' => false]);

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '7501234567890')
            ->call('escanearOBuscar')
            ->assertSet('carrito', [])
            ->assertNotDispatched('producto-escaneado')
            ->assertNotified();
    }

    public function test_producto_sin_stock_no_se_agrega_y_notifica(): void
    {
        $this->producto(['codigo_barra' => '7501234567890', 'controla_stock' => true, 'stock' => 0]);

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '7501234567890')
            ->call('escanearOBuscar')
            ->assertSet('carrito', [])
            ->assertNotified();
    }

    public function test_producto_sin_control_de_stock_se_agrega_aunque_stock_sea_cero(): void
    {
        $producto = $this->producto(['codigo_barra' => '7501234567890', 'controla_stock' => false, 'stock' => 0]);

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '7501234567890')
            ->call('escanearOBuscar')
            ->assertSet('carrito.0.producto_id', $producto->id);
    }

    public function test_la_busqueda_por_nombre_sigue_funcionando_igual(): void
    {
        $producto = $this->producto(['nombre' => 'Leche Entera']);

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'Leche');

        $this->assertCount(1, $componente->instance()->productosSugeridos());
        $this->assertSame($producto->id, $componente->instance()->productosSugeridos()->first()->id);
    }

    public function test_campo_vacio_no_hace_nada_al_presionar_enter(): void
    {
        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '')
            ->call('escanearOBuscar')
            ->assertSet('carrito', [])
            ->assertNotDispatched('producto-escaneado');
    }
}
