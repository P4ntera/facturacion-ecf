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

class PuntoDeVentaTest extends TestCase
{
    use RefreshDatabase;

    private function vendedor(): User
    {
        Permission::firstOrCreate(['name' => 'registrar_ventas', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['registrar_ventas']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function producto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo' => 'POS-001',
            'nombre' => 'Producto POS',
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

    public function test_selecciona_consumidor_final_por_defecto_al_montar(): void
    {
        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->assertSet('totales.total', '0.00')
            ->assertSee('Consumidor Final');
    }

    public function test_agregar_producto_lo_suma_al_carrito_y_recalcula_totales(): void
    {
        $producto = $this->producto();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->assertSet('carrito.0.producto_id', $producto->id)
            ->assertSet('totales.subtotal', '100.00')
            ->assertSet('totales.itbis_18', '18.00')
            ->assertSet('totales.total', '118.00');
    }

    public function test_agregar_el_mismo_producto_dos_veces_incrementa_la_cantidad(): void
    {
        $producto = $this->producto();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->call('agregarProducto', $producto->id)
            ->assertSet('carrito.0.cantidad', 2)
            ->assertSet('totales.total', '236.00');
    }

    public function test_quitar_linea_recalcula_los_totales_en_cero(): void
    {
        $producto = $this->producto();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->call('quitarLinea', 0)
            ->assertSet('carrito', [])
            ->assertSet('totales.total', '0.00');
    }

    public function test_cantidad_mayor_al_stock_deshabilita_cobrar(): void
    {
        $producto = $this->producto(['stock' => 1]);

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->set('carrito.0.cantidad', 5);

        $this->assertTrue($componente->instance()->hayLineasConStockInsuficiente());
        $this->assertFalse($componente->instance()->puedeCobrar());
    }

    public function test_un_usuario_sin_permiso_no_puede_acceder(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(PuntoDeVenta::getUrl())
            ->assertForbidden();
    }

    public function test_la_pagina_trae_el_wrapper_del_design_system_y_el_script_de_sidebar(): void
    {
        $response = $this->actingAs($this->vendedor())->get(PuntoDeVenta::getUrl());

        $response->assertOk();
        $response->assertSee('class="pos-screen"', false);
        $response->assertSee('$store.sidebar.close()', false);
        $response->assertSee('livewire:navigate', false);
        $response->assertSee('class="pos-grid"', false);
        $response->assertSee('class="pos-main"', false);
        $response->assertSee('class="pos-side"', false);
        $response->assertSee('<thead>', false);
        $response->assertSee('btn-cobrar', false);
    }
}
