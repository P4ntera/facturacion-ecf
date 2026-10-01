<?php

namespace Tests\Feature;

use App\Enums\EstadoPedidoCompra;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Resources\CompraResource\Pages\CreateCompra;
use App\Models\Compra;
use App\Models\PedidoCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CompraService;
use App\Services\PedidoCompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * "Recibir" un pedido de compra: la compra queda ligada al pedido (pedido_compra_id) y el pedido
 * pasa a RECIBIDO. Si la compra se anula, el pedido vuelve a PENDIENTE.
 */
class RecepcionPedidoCompraTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Proveedor $proveedor;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->proveedor = Proveedor::factory()->create();
        $this->producto = Producto::create([
            'codigo' => 'P-001',
            'nombre' => 'Producto Test',
            'tipo' => TipoProducto::PRODUCTO->value,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    private function crearPedido(?Proveedor $proveedor = null): PedidoCompra
    {
        return app(PedidoCompraService::class)->crear([
            'proveedor_id' => ($proveedor ?? $this->proveedor)->id,
            'fecha' => now(),
            'notas' => null,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 5, 'costo_unitario' => 60]],
        ], $this->user->id, $this->empresaDefault);
    }

    private function recibir(PedidoCompra $pedido, ?Proveedor $proveedor = null, float $cantidad = 5, string $ncf = 'B0100000001'): Compra
    {
        return app(CompraService::class)->crear([
            'proveedor_id' => ($proveedor ?? $this->proveedor)->id,
            'pedido_compra_id' => $pedido->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
            'ncf' => $ncf,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad, 'costo_unitario' => 60]],
        ], $this->user->id, $this->empresaDefault);
    }

    public function test_recibir_un_pedido_liga_la_compra_y_lo_marca_recibido(): void
    {
        $pedido = $this->crearPedido();

        $compra = $this->recibir($pedido);

        $this->assertSame($pedido->id, $compra->pedido_compra_id);
        $pedido->refresh();
        $this->assertSame(EstadoPedidoCompra::RECIBIDO, $pedido->estado);
        $this->assertNotNull($pedido->recibido_en);
        $this->assertEquals(15, (float) $this->producto->fresh()->stock);
    }

    public function test_se_puede_recibir_con_cantidades_distintas_a_las_pedidas(): void
    {
        $pedido = $this->crearPedido();

        $this->recibir($pedido, cantidad: 3);

        $this->assertTrue($pedido->fresh()->estaRecibido());
        $this->assertEquals(13, (float) $this->producto->fresh()->stock);
    }

    public function test_compra_sin_pedido_sigue_funcionando(): void
    {
        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
            'ncf' => 'B0100000001',
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'costo_unitario' => 60]],
        ], $this->user->id, $this->empresaDefault);

        $this->assertNull($compra->pedido_compra_id);
    }

    public function test_no_se_puede_recibir_dos_veces_el_mismo_pedido(): void
    {
        $pedido = $this->crearPedido();
        $this->recibir($pedido);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya no está pendiente');

        $this->recibir($pedido);
    }

    public function test_no_se_puede_recibir_un_pedido_cancelado(): void
    {
        $pedido = $this->crearPedido();
        app(PedidoCompraService::class)->cancelar($pedido, 'Ya no hace falta', $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya no está pendiente');

        $this->recibir($pedido);
    }

    public function test_no_se_puede_recibir_con_otro_proveedor_y_no_se_crea_nada(): void
    {
        $pedido = $this->crearPedido();
        $otroProveedor = Proveedor::factory()->create();

        try {
            $this->recibir($pedido, $otroProveedor);
            $this->fail('Debió rechazar el proveedor distinto.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('otro proveedor', $e->getMessage());
        }

        $this->assertSame(0, Compra::count());
        $this->assertTrue($pedido->fresh()->estaPendiente());
        $this->assertEquals(10, (float) $this->producto->fresh()->stock);
    }

    public function test_anular_la_compra_devuelve_el_pedido_a_pendiente(): void
    {
        $pedido = $this->crearPedido();
        $compra = $this->recibir($pedido);

        app(CompraService::class)->anular($compra, 'Factura equivocada', $this->user->id);

        $pedido->refresh();
        $this->assertSame(EstadoPedidoCompra::PENDIENTE, $pedido->estado);
        $this->assertNull($pedido->recibido_en);

        // Y se puede volver a recibir (con la factura correcta del proveedor).
        $this->assertSame($pedido->id, $this->recibir($pedido, ncf: 'B0100000002')->pedido_compra_id);
    }

    public function test_no_se_puede_cancelar_un_pedido_recibido(): void
    {
        $pedido = $this->crearPedido();
        $this->recibir($pedido);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya fue recibido');

        app(PedidoCompraService::class)->cancelar($pedido->fresh(), 'Error', $this->user->id);
    }

    public function test_el_formulario_de_compra_se_prellena_desde_el_pedido(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Almacenista');
        $pedido = $this->crearPedido();

        $componente = Livewire::actingAs($this->user)
            ->withQueryParams(['pedido' => $pedido->id])
            ->test(CreateCompra::class)
            ->assertSet('data.pedido_compra_id', $pedido->id)
            ->assertSet('data.proveedor_id', $this->proveedor->id);

        $lineas = array_values($componente->get('data.lineas'));
        $this->assertCount(1, $lineas);
        $this->assertSame($this->producto->id, $lineas[0]['producto_id']);
        $this->assertEquals(5, $lineas[0]['cantidad']);
        $this->assertEquals(60, $lineas[0]['costo_unitario']);
    }

    public function test_el_formulario_no_se_prellena_con_un_pedido_ya_recibido(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Almacenista');
        $pedido = $this->crearPedido();
        $this->recibir($pedido);

        Livewire::actingAs($this->user)
            ->withQueryParams(['pedido' => $pedido->id])
            ->test(CreateCompra::class)
            ->assertSet('data.pedido_compra_id', null)
            ->assertNotified('El pedido de compra no existe o ya no está pendiente.');
    }
}
