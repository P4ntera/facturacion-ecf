<?php

namespace Tests\Feature;

use App\Enums\AmbienteEcf;
use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Resources\VentaResource\Pages\ListVentas;
use App\Filament\Resources\VentaResource\Pages\ViewVenta;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VentaResourceTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'registrar_ventas', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'anular_ventas', 'guard_name' => 'web']);

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 100,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'VR-001',
            'nombre' => 'Producto VentaResource',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);
    }

    private function crearVenta(): Venta
    {
        $cliente = Cliente::create(['nombre' => 'Cliente Resource', 'activo' => true]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'user_id' => null,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 2]],
        ]);
    }

    private function usuarioConPermisos(array $permisos): User
    {
        $rol = Role::firstOrCreate(['name' => 'Rol-'.implode('-', $permisos), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    public function test_lista_ventas_para_quien_tiene_permiso(): void
    {
        $this->crearVenta();

        Livewire::actingAs($this->usuarioConPermisos(['registrar_ventas']))
            ->test(ListVentas::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Venta::all());
    }

    public function test_anular_repone_stock_y_marca_anulada(): void
    {
        $venta = $this->crearVenta();
        $this->assertSame('8.000', $this->producto->refresh()->stock);

        Livewire::actingAs($this->usuarioConPermisos(['registrar_ventas', 'anular_ventas']))
            ->test(ListVentas::class)
            ->callTableAction('anular', $venta, data: ['motivo' => 'Prueba de anulación'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('10.000', $this->producto->refresh()->stock);
        $this->assertSame('anulada', $venta->refresh()->estado->value);
        $this->assertSame('Prueba de anulación', $venta->motivo_anulacion);
    }

    public function test_accion_anular_no_visible_sin_permiso(): void
    {
        $venta = $this->crearVenta();

        Livewire::actingAs($this->usuarioConPermisos(['registrar_ventas']))
            ->test(ListVentas::class)
            ->assertTableActionHidden('anular', $venta);
    }

    public function test_accion_anular_no_visible_si_ya_esta_anulada(): void
    {
        $venta = $this->crearVenta();
        app(VentaService::class)->anular($venta, 'motivo previo', null);

        Livewire::actingAs($this->usuarioConPermisos(['registrar_ventas', 'anular_ventas']))
            ->test(ListVentas::class)
            ->assertTableActionHidden('anular', $venta->refresh());
    }

    public function test_filtra_por_estado_fiscal_y_por_ambiente(): void
    {
        // El FakeGateway (cola 'sync' en pruebas) acepta la venta automáticamente al cobrar.
        $venta = $this->crearVenta()->refresh();
        $this->assertSame(EstadoFiscal::ACEPTADO, $venta->estado_fiscal);
        $this->assertSame(AmbienteEcf::TESTECF, $venta->ambiente);

        Livewire::actingAs($this->usuarioConPermisos(['registrar_ventas']))
            ->test(ListVentas::class)
            ->filterTable('estado_fiscal', EstadoFiscal::ACEPTADO->value)
            ->assertCanSeeTableRecords([$venta])
            ->filterTable('estado_fiscal', EstadoFiscal::RECHAZADO->value)
            ->assertCanNotSeeTableRecords([$venta])
            ->removeTableFilter('estado_fiscal')
            ->filterTable('ambiente', AmbienteEcf::TESTECF->value)
            ->assertCanSeeTableRecords([$venta])
            ->filterTable('ambiente', AmbienteEcf::ECF->value)
            ->assertCanNotSeeTableRecords([$venta]);
    }

    public function test_la_pagina_de_ver_muestra_cabecera_y_lineas(): void
    {
        $venta = $this->crearVenta();

        Livewire::actingAs($this->usuarioConPermisos(['registrar_ventas']))
            ->test(ViewVenta::class, ['record' => $venta->id])
            ->assertSuccessful()
            ->assertSee($venta->ncf)
            ->assertSee('Producto VentaResource');
    }
}
