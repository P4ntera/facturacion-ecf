<?php

namespace Tests\Feature;

use App\Enums\EstadoPedidoCompra;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\PedidoCompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PedidoCompraServiceTest extends TestCase
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
            'stock'          => 10,
            'stock_minimo'   => 0,
            'activo'         => true,
        ], $overrides));
    }

    public function test_crear_pedido_calcula_totales_correctamente(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();

        $pedido = app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => 'Reposición de stock',
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $user->id);

        $this->assertEquals(EstadoPedidoCompra::PENDIENTE, $pedido->estado);
        $this->assertEquals(300.00, (float) $pedido->subtotal);
        $this->assertEquals(54.00, (float) $pedido->itbis); // 300 * 18%
        $this->assertEquals(354.00, (float) $pedido->total);

        $detalle = $pedido->detalles()->first();
        $this->assertEquals(TasaItbis::DIECIOCHO, $detalle->tasa_itbis);
        $this->assertEquals(60.00, (float) $detalle->costo_unitario);
    }

    public function test_crear_pedido_no_afecta_stock_ni_costo_del_producto(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto(['stock' => 10, 'costo' => 50]);
        $user      = User::factory()->create();

        app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $user->id);

        $producto->refresh();
        $this->assertEquals(10, (float) $producto->stock);
        $this->assertEquals(50.00, (float) $producto->costo);

        $this->assertEquals(0, MovimientoInventario::where('producto_id', $producto->id)->count());
    }

    public function test_crear_pedido_lanza_excepcion_si_no_hay_lineas(): void
    {
        $proveedor = Proveedor::factory()->create();
        $user      = User::factory()->create();

        $this->expectException(RuntimeException::class);

        app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas'       => [],
        ], $user->id);
    }

    public function test_cancelar_pedido_marca_estado_motivo_y_fecha(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();

        $service = app(PedidoCompraService::class);
        $pedido  = $service->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $user->id);

        $pedido = $service->cancelar($pedido, 'Ya no se necesita', $user->id);

        $this->assertEquals(EstadoPedidoCompra::CANCELADO, $pedido->estado);
        $this->assertEquals('Ya no se necesita', $pedido->motivo_cancelacion);
        $this->assertNotNull($pedido->cancelado_en);
    }

    public function test_no_permite_cancelar_dos_veces(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();

        $service = app(PedidoCompraService::class);
        $pedido  = $service->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $user->id);

        $service->cancelar($pedido, 'Motivo', $user->id);

        $this->expectException(RuntimeException::class);
        $service->cancelar($pedido, 'Otro motivo', $user->id);
    }

    public function test_marcar_enviado_registra_fecha_y_correo(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();

        $service = app(PedidoCompraService::class);
        $pedido  = $service->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $user->id);

        $pedido = $service->marcarEnviado($pedido, 'proveedor@test.com', $user->id);

        $this->assertNotNull($pedido->enviado_en);
        $this->assertEquals('proveedor@test.com', $pedido->enviado_a);
        $this->assertTrue($pedido->fueEnviado());
    }

    public function test_no_permite_marcar_enviado_en_pedido_cancelado(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();

        $service = app(PedidoCompraService::class);
        $pedido  = $service->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $user->id);

        $service->cancelar($pedido, 'Motivo', $user->id);

        $this->expectException(RuntimeException::class);
        $service->marcarEnviado($pedido, 'proveedor@test.com', $user->id);
    }

    public function test_gestionar_compras_habilita_crear_y_solo_administrador_cancela(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Administrador');

        $almacenista = User::factory()->create();
        $almacenista->assignRole('Almacenista');

        $this->assertTrue($admin->can('gestionar_compras'));
        $this->assertTrue($almacenista->can('gestionar_compras'));

        $this->assertTrue($admin->can('anular_compras'));
        $this->assertFalse($almacenista->can('anular_compras'));
    }
}
