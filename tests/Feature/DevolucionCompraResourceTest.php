<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Resources\DevolucionCompraResource;
use App\Filament\Resources\DevolucionCompraResource\Pages\CreateDevolucionCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DevolucionCompraResourceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        return $usuario;
    }

    public function test_la_pagina_indice_de_devoluciones_carga_para_quien_tiene_permiso(): void
    {
        $usuario = $this->usuarioConPermiso();

        $this->actingAs($usuario)
            ->get(DevolucionCompraResource::getUrl('index'))
            ->assertOk();
    }

    public function test_usuario_sin_permiso_no_puede_entrar_a_devoluciones(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(DevolucionCompraResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_el_formulario_de_crear_devolucion_carga_precargado_con_la_compra(): void
    {
        $usuario   = $this->usuarioConPermiso();
        $proveedor = Proveedor::factory()->create();
        $producto  = Producto::create([
            'codigo'         => 'P-DEV-001',
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

        $compra = app(CompraService::class)->crear([
            'proveedor_id'     => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf'              => null,
            'fecha'            => now(),
            'itbis_incluido'   => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $usuario->id);

        Livewire::actingAs($usuario)
            ->withQueryParams(['compra_id' => $compra->id])
            ->test(CreateDevolucionCompra::class)
            ->assertOk()
            ->assertFormSet(['compra_id' => $compra->id]);
    }
}
