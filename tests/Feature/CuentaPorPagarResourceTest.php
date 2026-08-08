<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Filament\Resources\CuentaPorPagarResource;
use App\Filament\Resources\CuentaPorPagarResource\Pages\ListCuentasPorPagar;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CuentaPorPagarResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function usuarioConPermisos(array $permisos): User
    {
        $rol = Role::firstOrCreate(['name' => 'Rol-'.implode('-', $permisos), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function crearCompraACredito(): Compra
    {
        $proveedor = Proveedor::factory()->create();
        $producto = Producto::create([
            'codigo' => 'CXP-RES-1',
            'nombre' => 'Producto CxP Resource',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        return app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'tipo_pago' => TipoPago::CREDITO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 100]],
        ], User::factory()->create()->id);
    }

    public function test_la_pagina_indice_carga_para_quien_tiene_permiso(): void
    {
        $this->crearCompraACredito();

        Livewire::actingAs($this->usuarioConPermisos(['cxp.ver']))
            ->test(ListCuentasPorPagar::class)
            ->assertSuccessful();
    }

    public function test_usuario_sin_permiso_no_puede_entrar(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(CuentaPorPagarResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_registrar_pago_desde_la_tabla_actualiza_la_cuenta(): void
    {
        $compra = $this->crearCompraACredito();

        Livewire::actingAs($this->usuarioConPermisos(['cxp.ver', 'cxp.pagar']))
            ->test(ListCuentasPorPagar::class)
            ->callTableAction('registrarPago', $compra->cuentaPorPagar, data: [
                'monto' => 118,
                'fecha' => now()->toDateString(),
                'forma_pago' => FormaPago::TRANSFERENCIA->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('pagada', $compra->cuentaPorPagar->fresh()->estado()->value);
    }

    public function test_accion_registrar_pago_no_visible_sin_permiso(): void
    {
        $compra = $this->crearCompraACredito();

        Livewire::actingAs($this->usuarioConPermisos(['cxp.ver']))
            ->test(ListCuentasPorPagar::class)
            ->assertTableActionHidden('registrarPago', $compra->cuentaPorPagar);
    }
}
