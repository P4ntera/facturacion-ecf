<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Filament\Resources\CuentaPorCobrarResource;
use App\Filament\Resources\CuentaPorCobrarResource\Pages\ListCuentasPorCobrar;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CuentaPorCobrarResourceTest extends TestCase
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

    private function crearVentaACredito(): \App\Models\Venta
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

        $producto = Producto::create([
            'codigo' => 'CXC-RES-1',
            'nombre' => 'Producto CxC Resource',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $cliente = Cliente::create(['nombre' => 'Cliente Resource', 'activo' => true]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_pago' => TipoPago::CREDITO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    public function test_la_pagina_indice_carga_para_quien_tiene_permiso(): void
    {
        $this->crearVentaACredito();

        Livewire::actingAs($this->usuarioConPermisos(['cxc.ver']))
            ->test(ListCuentasPorCobrar::class)
            ->assertSuccessful();
    }

    public function test_usuario_sin_permiso_no_puede_entrar(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(CuentaPorCobrarResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_registrar_pago_desde_la_tabla_actualiza_la_cuenta(): void
    {
        $venta = $this->crearVentaACredito();

        Livewire::actingAs($this->usuarioConPermisos(['cxc.ver', 'cxc.cobrar']))
            ->test(ListCuentasPorCobrar::class)
            ->callTableAction('registrarPago', $venta->cuentaPorCobrar, data: [
                'monto' => 118,
                'fecha' => now()->toDateString(),
                'forma_pago' => FormaPago::EFECTIVO->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('pagada', $venta->cuentaPorCobrar->fresh()->estado()->value);
    }

    public function test_accion_registrar_pago_no_visible_sin_permiso(): void
    {
        $venta = $this->crearVentaACredito();

        Livewire::actingAs($this->usuarioConPermisos(['cxc.ver']))
            ->test(ListCuentasPorCobrar::class)
            ->assertTableActionHidden('registrarPago', $venta->cuentaPorCobrar);
    }
}
