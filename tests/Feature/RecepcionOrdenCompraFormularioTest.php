<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Filament\Resources\CompraResource;
use App\Filament\Resources\CompraResource\Pages\CreateCompra;
use App\Filament\Resources\OrdenCompraResource\Pages\CreateOrdenCompra;
use App\Filament\Resources\OrdenCompraResource\Pages\ViewOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\OrdenCompraService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pantallas del flujo unificado de compras: Stock bajo → orden de compra → "Recibir mercancía"
 * abre la compra prellenada con lo pendiente de la orden.
 */
class RecepcionOrdenCompraFormularioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Proveedor $proveedor;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        app(RolesEmpresaService::class)->asignarAdministrador($this->admin, $this->empresaDefault);
        $this->actingAs($this->admin);

        $this->proveedor = Proveedor::create([
            'nombre' => 'Distribuidora Test', 'tipo' => TipoProveedor::FORMAL, 'rnc' => '130000088', 'activo' => true,
        ]);
        $this->producto = Producto::create([
            'codigo' => 'P-1', 'nombre' => 'Producto Test', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50, 'precio' => 80, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 2, 'stock_minimo' => 10, 'activo' => true,
        ]);
    }

    private function ordenEnviada(float $cantidad = 10): OrdenCompra
    {
        $servicio = app(OrdenCompraService::class);
        $orden = $servicio->crear([
            'proveedor_id' => $this->proveedor->id,
            'fecha' => now()->toDateString(),
            'fecha_esperada' => null,
            'notas' => null,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad_solicitada' => $cantidad, 'precio_unitario' => 55]],
        ], $this->admin->id, $this->empresaDefault);
        $servicio->aprobar($orden);

        return $orden->refresh();
    }

    public function test_recibir_mercancia_abre_la_compra_con_la_orden(): void
    {
        $orden = $this->ordenEnviada();

        Livewire::test(ViewOrdenCompra::class, ['record' => $orden->id])
            ->assertActionVisible('recibirMercancia')
            ->assertActionHasUrl('recibirMercancia', CompraResource::getUrl('create', ['orden' => $orden->id]));
    }

    public function test_la_compra_se_prellena_con_lo_pendiente_de_la_orden(): void
    {
        $orden = $this->ordenEnviada(10);
        $orden->detalles()->first()->update(['cantidad_recibida' => 4]); // ya llegaron 4

        $componente = Livewire::withQueryParams(['orden' => $orden->id])
            ->test(CreateCompra::class)
            ->assertSet('data.orden_compra_id', $orden->id)
            ->assertSet('data.proveedor_id', $this->proveedor->id);

        $lineas = array_values($componente->get('data.lineas'));
        $this->assertCount(1, $lineas);
        $this->assertSame($this->producto->id, $lineas[0]['producto_id']);
        $this->assertEquals(6, $lineas[0]['cantidad']);        // solo lo pendiente
        $this->assertEquals(55, $lineas[0]['costo_unitario']); // al precio de la orden
    }

    public function test_una_orden_en_borrador_no_prellena_la_compra(): void
    {
        $orden = app(OrdenCompraService::class)->crear([
            'proveedor_id' => $this->proveedor->id,
            'fecha' => now()->toDateString(),
            'fecha_esperada' => null,
            'notas' => null,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad_solicitada' => 5, 'precio_unitario' => 55]],
        ], $this->admin->id, $this->empresaDefault);

        Livewire::withQueryParams(['orden' => $orden->id])
            ->test(CreateCompra::class)
            ->assertSet('data.orden_compra_id', null)
            ->assertNotified('La orden de compra no existe o no admite recepciones.');
    }

    public function test_stock_bajo_crea_la_orden_prellenada(): void
    {
        $this->producto->proveedores()->attach($this->proveedor->id, ['es_principal' => true, 'costo_referencia' => 48]);

        $componente = Livewire::withQueryParams([
            'proveedor_id' => $this->proveedor->id,
            'producto_ids' => (string) $this->producto->id,
        ])
            ->test(CreateOrdenCompra::class)
            ->assertOk()
            ->assertSet('data.proveedor_id', $this->proveedor->id);

        $lineas = array_values($componente->get('data.lineas'));
        $this->assertCount(1, $lineas);
        $this->assertEquals(8, $lineas[0]['cantidad_solicitada']); // mínimo 10 − stock 2
        $this->assertEquals(48, $lineas[0]['precio_unitario']);    // costo de referencia con el proveedor
    }
}
