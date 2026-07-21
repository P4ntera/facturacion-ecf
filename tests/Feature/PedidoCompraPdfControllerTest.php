<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\PedidoCompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PedidoCompraPdfControllerTest extends TestCase
{
    use RefreshDatabase;

    private function crearPedido(): \App\Models\PedidoCompra
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = Producto::create([
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
        ]);

        $user = User::factory()->create();

        return app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $user->id);
    }

    public function test_descarga_pdf_de_pedido_compra(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        $pedido = $this->crearPedido();

        $this->actingAs($usuario)
            ->get(route('pedidos-compra.pdf', $pedido))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_usuario_sin_permiso_no_puede_descargar_pdf(): void
    {
        $usuario = User::factory()->create();
        $pedido  = $this->crearPedido();

        $this->actingAs($usuario)
            ->get(route('pedidos-compra.pdf', $pedido))
            ->assertForbidden();
    }
}
