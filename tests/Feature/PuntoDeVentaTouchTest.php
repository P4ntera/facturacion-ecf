<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Filament\Pages\PuntoDeVentaTouch;
use App\Models\ArqueoCaja;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Role;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\ArqueoCajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PuntoDeVentaTouchTest extends TestCase
{
    use RefreshDatabase;

    private function cajero(): User
    {
        Permission::firstOrCreate(['name' => 'pos.acceder', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['empresa_id' => $this->empresaDefault->id, 'name' => 'Cajero', 'guard_name' => 'web']);
        $rol->syncPermissions(['pos.acceder']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Cajero');

        return $usuario;
    }

    private function caja(string $nombre = 'Caja 1', array $overrides = []): Caja
    {
        return Caja::create(array_merge(['nombre' => $nombre, 'activo' => true], $overrides));
    }

    private function producto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo' => 'TOUCH-001',
            'nombre' => 'Arroz 5lb',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 50,
            'stock_minimo' => 1,
            'activo' => true,
        ], $overrides));
    }

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

    public function test_la_pagina_se_sirve_a_pantalla_completa_sin_la_navegacion_del_panel(): void
    {
        $this->actingAs($this->cajero())
            ->get(PuntoDeVentaTouch::getUrl(tenant: $this->empresaDefault))
            ->assertOk()
            ->assertSee('¿En qué caja vas a trabajar?')
            ->assertDontSee('fi-sidebar', false)
            ->assertDontSee('fi-topbar', false);
    }

    public function test_sin_permiso_pos_acceder_no_entra(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(PuntoDeVentaTouch::getUrl(tenant: $this->empresaDefault))
            ->assertForbidden();
    }

    public function test_el_selector_solo_ofrece_cajas_activas_de_la_empresa(): void
    {
        $this->caja('Caja Activa');
        $this->caja('Caja Apagada', ['activo' => false]);
        $otra = Empresa::factory()->create();
        Caja::create(['empresa_id' => $otra->id, 'nombre' => 'Caja Ajena', 'activo' => true]);

        Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->assertSee('Caja Activa')
            ->assertDontSee('Caja Apagada')
            ->assertDontSee('Caja Ajena');
    }

    public function test_no_puede_seleccionar_una_caja_de_otra_empresa_ni_inactiva(): void
    {
        $otra = Empresa::factory()->create();
        $ajena = Caja::create(['empresa_id' => $otra->id, 'nombre' => 'Caja Ajena', 'activo' => true]);
        $inactiva = $this->caja('Caja Apagada', ['activo' => false]);

        Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $ajena->id)
            ->assertSet('cajaId', null)
            ->call('seleccionarCaja', $inactiva->id)
            ->assertSet('cajaId', null);
    }

    public function test_un_caja_id_manipulado_desde_el_navegador_no_sirve_para_cobrar(): void
    {
        $this->secuenciaConsumo();
        $producto = $this->producto();
        $otra = Empresa::factory()->create();
        $ajena = Caja::create(['empresa_id' => $otra->id, 'nombre' => 'Caja Ajena', 'activo' => true]);

        Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->set('cajaId', $ajena->id)
            ->call('abrirCaja', '0')
            ->call('tocarProducto', $producto->id)
            ->call('cobrar');

        $this->assertSame(0, Venta::count());
        $this->assertSame(0, ArqueoCaja::count());
    }

    public function test_abrir_turno_asocia_el_arqueo_a_la_caja_seleccionada(): void
    {
        $cajero = $this->cajero();
        $caja = $this->caja();

        Livewire::actingAs($cajero)
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->assertSee('Abrir turno en Caja 1')
            ->call('abrirCaja', '1000')
            ->assertDontSee('Abrir turno en Caja 1');

        $this->assertDatabaseHas('arqueos_caja', [
            'user_id' => $cajero->id,
            'caja_id' => $caja->id,
            'empresa_id' => $this->empresaDefault->id,
            'fondo_inicial' => '1000.00',
        ]);
    }

    public function test_con_un_turno_abierto_en_una_caja_entra_directo_a_ella(): void
    {
        $cajero = $this->cajero();
        $caja = $this->caja();
        app(ArqueoCajaService::class)->abrir('0', $cajero->id, $this->empresaDefault, $caja);

        Livewire::actingAs($cajero)
            ->test(PuntoDeVentaTouch::class)
            ->assertSet('cajaId', $caja->id)
            ->call('cambiarCaja')
            ->assertSet('cajaId', $caja->id);
    }

    public function test_un_turno_abierto_sin_caja_no_se_puede_usar_en_una_caja_fisica(): void
    {
        $cajero = $this->cajero();
        $caja = $this->caja();
        app(ArqueoCajaService::class)->abrir('0', $cajero->id, $this->empresaDefault);

        Livewire::actingAs($cajero)
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->assertSet('cajaId', null);
    }

    public function test_cobrar_registra_la_venta_con_caja_id_y_arqueo_del_turno(): void
    {
        $this->secuenciaConsumo();
        $cajero = $this->cajero();
        $caja = $this->caja();
        $producto = $this->producto();

        Livewire::actingAs($cajero)
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '500')
            ->call('tocarProducto', $producto->id, '2')
            ->set('tipoComprobante', TipoComprobante::FACTURA_CONSUMO->value)
            ->assertSet('totales.total', '236.00')
            ->call('cobrar')
            ->assertSet('carrito', [])
            ->assertDispatched('venta-cobrada');

        $venta = Venta::sole();
        $this->assertSame($caja->id, $venta->caja_id);
        $this->assertSame(ArqueoCaja::sole()->id, $venta->arqueo_caja_id);
        $this->assertSame('236.00', (string) $venta->total);
        $this->assertSame('48.000', (string) $producto->refresh()->stock);
    }

    public function test_no_cobra_un_tipo_de_comprobante_fuera_del_modal(): void
    {
        $this->secuenciaConsumo();
        $caja = $this->caja();
        $producto = $this->producto();

        $componente = Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '0')
            ->call('tocarProducto', $producto->id);

        $this->assertArrayNotHasKey(TipoComprobante::NOTA_CREDITO->value, $componente->instance()->tiposComprobante());

        $componente->set('tipoComprobante', TipoComprobante::NOTA_CREDITO->value)->call('cobrar');

        $this->assertSame(0, Venta::count());
    }

    public function test_teclado_numerico_cantidad_antes_del_producto_y_edicion_de_linea(): void
    {
        $caja = $this->caja();
        $producto = $this->producto();

        Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '0')
            ->call('tocarProducto', $producto->id, '3')
            ->assertSet('carrito.0.cantidad', 3)
            ->assertSet('lineaSeleccionada', 0)
            ->call('tocarProducto', $producto->id)
            ->assertSet('carrito.0.cantidad', 4)
            ->call('establecerCantidadLinea', 0, '2.7')
            ->assertSet('carrito.0.cantidad', 2)
            ->call('sumarALinea', 0, 1)
            ->assertSet('carrito.0.cantidad', 3)
            ->call('establecerDescuentoLinea', 0, '50')
            ->assertSet('carrito.0.descuento', '50.00')
            ->call('establecerDescuentoLinea', 0, '300')
            ->assertSet('carrito.0.descuento', '50.00')
            ->call('establecerCantidadLinea', 0, '0')
            ->assertSet('carrito', []);
    }

    public function test_producto_por_peso_exige_el_peso_tecleado(): void
    {
        $caja = $this->caja();
        $pesado = $this->producto([
            'codigo' => 'PESO-1',
            'nombre' => 'Salami',
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'lb',
            'precio_por_peso' => 89,
            'controla_stock' => false,
        ]);

        Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '0')
            ->call('tocarProducto', $pesado->id)
            ->assertSet('carrito', [])
            ->call('tocarProducto', $pesado->id, '1.5')
            ->assertSet('carrito.0.cantidad', '1.5')
            ->assertSet('carrito.0.tipo_venta', TipoVenta::PESADO->value);
    }

    public function test_grid_filtra_por_categoria_y_no_muestra_productos_de_otra_empresa(): void
    {
        $caja = $this->caja();
        $bebidas = Categoria::create(['nombre' => 'Bebidas', 'activo' => true]);
        $this->producto(['codigo' => 'B1', 'nombre' => 'Refresco', 'categoria_id' => $bebidas->id]);
        $this->producto(['codigo' => 'A1', 'nombre' => 'Arroz']);
        $otra = Empresa::factory()->create();
        Producto::create([
            'empresa_id' => $otra->id, 'codigo' => 'X1', 'nombre' => 'Producto Ajeno', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => false,
            'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $componente = Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '0')
            ->assertSee('Refresco')
            ->assertSee('Arroz')
            ->assertDontSee('Producto Ajeno')
            ->call('seleccionarCategoria', $bebidas->id);

        $nombres = $componente->instance()->productosGrid()->pluck('nombre')->all();
        $this->assertSame(['Refresco'], $nombres);
    }

    public function test_el_carrito_se_publica_para_el_display_y_tras_cobrar_queda_en_gracias(): void
    {
        $this->secuenciaConsumo();
        $caja = $this->caja();
        $producto = $this->producto();

        $componente = Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '0')
            ->call('tocarProducto', $producto->id, '2');

        $datos = Cache::get($caja->claveCacheDisplay());
        $this->assertSame('venta', $datos['estado']);
        $this->assertSame('Arroz 5lb', $datos['items'][0]['nombre']);
        $this->assertSame('2', $datos['items'][0]['cantidad']);
        $this->assertSame('236.00', $datos['total']);
        $this->assertArrayNotHasKey('producto_id', $datos['items'][0]);

        $componente->set('tipoComprobante', TipoComprobante::FACTURA_CONSUMO->value)->call('cobrar');

        $datos = Cache::get($caja->claveCacheDisplay());
        $this->assertSame('gracias', $datos['estado']);
        $this->assertSame([], $datos['items']);
        $this->assertSame('236.00', $datos['total']);
    }

    public function test_cancelar_venta_limpia_carrito_y_display(): void
    {
        $caja = $this->caja();
        $producto = $this->producto();

        Livewire::actingAs($this->cajero())
            ->test(PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '0')
            ->call('tocarProducto', $producto->id)
            ->call('cancelarVenta')
            ->assertSet('carrito', []);

        $this->assertSame('libre', Cache::get($caja->claveCacheDisplay())['estado']);
    }
}
