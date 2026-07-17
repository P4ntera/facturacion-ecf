<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Resources\PedidoCompraResource;
use App\Filament\Resources\PedidoCompraResource\Pages\CreatePedidoCompra;
use App\Filament\Resources\PedidoCompraResource\Pages\ListPedidosCompra;
use App\Mail\PedidoCompraEnviado;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\PedidoCompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PedidoCompraResourceTest extends TestCase
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
            'stock'          => 2,
            'stock_minimo'   => 10,
            'activo'         => true,
        ], $overrides));
    }

    private function usuarioConPermiso(string $rol = 'Almacenista'): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    public function test_la_pagina_indice_de_pedidos_carga_para_quien_tiene_permiso(): void
    {
        $usuario = $this->usuarioConPermiso();

        $this->actingAs($usuario)
            ->get(PedidoCompraResource::getUrl('index'))
            ->assertOk();
    }

    public function test_usuario_sin_permiso_no_puede_entrar_a_pedidos(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(PedidoCompraResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_el_formulario_de_crear_pedido_carga_sin_errores(): void
    {
        $usuario = $this->usuarioConPermiso();

        Livewire::actingAs($usuario)
            ->test(CreatePedidoCompra::class)
            ->assertOk();
    }

    public function test_crear_pedido_prefill_desde_query_params_del_dashboard(): void
    {
        $usuario   = $this->usuarioConPermiso();
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();

        $producto->proveedores()->attach($proveedor->id, ['es_principal' => true]);

        Livewire::actingAs($usuario)
            ->withQueryParams([
                'proveedor_id' => $proveedor->id,
                'producto_ids' => (string) $producto->id,
            ])
            ->test(CreatePedidoCompra::class)
            ->assertOk()
            ->assertFormSet(['proveedor_id' => $proveedor->id]);
    }

    public function test_enviar_pedido_por_correo_envia_mailable_con_pdf_adjunto(): void
    {
        Mail::fake();

        $usuario   = $this->usuarioConPermiso();
        $proveedor = Proveedor::factory()->create(['email' => 'proveedor@test.com']);
        $producto  = $this->crearProducto();

        $pedido = app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 8, 'costo_unitario' => 60],
            ],
        ], $usuario->id);

        Livewire::actingAs($usuario)
            ->test(ListPedidosCompra::class)
            ->callTableAction('enviarPorCorreo', $pedido, data: [
                'email' => 'proveedor@test.com',
            ])
            ->assertHasNoTableActionErrors();

        Mail::assertSent(PedidoCompraEnviado::class, function (PedidoCompraEnviado $mail) {
            return $mail->hasTo('proveedor@test.com') && count($mail->attachments()) === 1;
        });

        $this->assertNotNull($pedido->fresh()->enviado_en);
        $this->assertEquals('proveedor@test.com', $pedido->fresh()->enviado_a);
    }

    public function test_cancelar_solo_lo_puede_un_administrador(): void
    {
        $admin       = $this->usuarioConPermiso('Administrador');
        $almacenista = User::factory()->create();
        $almacenista->assignRole('Almacenista');

        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();

        $pedido = app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'fecha'        => now(),
            'notas'        => null,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $admin->id);

        Livewire::actingAs($almacenista)
            ->test(ListPedidosCompra::class)
            ->assertTableActionHidden('cancelar', $pedido);

        Livewire::actingAs($admin)
            ->test(ListPedidosCompra::class)
            ->assertTableActionVisible('cancelar', $pedido)
            ->callTableAction('cancelar', $pedido, data: ['motivo' => 'Ya no se necesita'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($pedido->fresh()->estaCancelado());
    }
}
