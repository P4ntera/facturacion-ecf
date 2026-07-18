<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Resources\VentaResource\Pages\ListVentas;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VentaEcfActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'ventas.ver', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ecf.gestionar', 'guard_name' => 'web']);
    }

    private function usuarioConPermisos(array $permisos): User
    {
        $rol = Role::firstOrCreate(['name' => 'Rol-ecf-'.implode('-', $permisos), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    /** El FakeGateway (cola 'sync' en pruebas) acepta la venta automáticamente al cobrar. */
    private function crearVentaAceptada(): Venta
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 100,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $producto = Producto::create([
            'codigo' => 'ECF-ACT-1',
            'nombre' => 'Producto acciones ecf',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $cliente = Cliente::create(['nombre' => 'Cliente acciones ecf', 'activo' => true]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->refresh();
    }

    public function test_refrescar_estado_no_es_visible_sin_el_permiso_ecf_gestionar(): void
    {
        $venta = $this->crearVentaAceptada();

        Livewire::actingAs($this->usuarioConPermisos(['ventas.ver']))
            ->test(ListVentas::class)
            ->assertTableActionHidden('refrescarEstado', $venta);
    }

    public function test_refrescar_estado_actualiza_el_estado_fiscal_desde_el_pac(): void
    {
        $venta = $this->crearVentaAceptada();

        Livewire::actingAs($this->usuarioConPermisos(['ventas.ver', 'ecf.gestionar']))
            ->test(ListVentas::class)
            ->callTableAction('refrescarEstado', $venta)
            ->assertHasNoTableActionErrors();

        $venta->refresh();
        $this->assertSame(EstadoFiscal::ACEPTADO, $venta->estado_fiscal);
        // consultarTrack (FakeGateway) agrega un evento TRACK_STATUS más al historial.
        $this->assertCount(4, data_get($venta->ecf_respuesta, 'eventos'));
    }

    public function test_refrescar_estado_no_visible_si_la_venta_no_se_ha_enviado_al_pac(): void
    {
        $venta = $this->crearVentaAceptada();
        $venta->update(['pac_id' => null]);

        Livewire::actingAs($this->usuarioConPermisos(['ventas.ver', 'ecf.gestionar']))
            ->test(ListVentas::class)
            ->assertTableActionHidden('refrescarEstado', $venta);
    }

    public function test_reintentar_envio_visible_solo_si_esta_pendiente_o_hubo_error(): void
    {
        $venta = $this->crearVentaAceptada();

        Livewire::actingAs($this->usuarioConPermisos(['ventas.ver', 'ecf.gestionar']))
            ->test(ListVentas::class)
            ->assertTableActionHidden('reintentarEnvio', $venta);

        $venta->update(['estado_fiscal' => EstadoFiscal::PENDIENTE]);

        Livewire::actingAs($this->usuarioConPermisos(['ventas.ver', 'ecf.gestionar']))
            ->test(ListVentas::class)
            ->assertTableActionVisible('reintentarEnvio', $venta->refresh());
    }

    public function test_reintentar_envio_despacha_el_job_a_la_cola_ecf(): void
    {
        $venta = $this->crearVentaAceptada();
        $venta->update(['estado_fiscal' => EstadoFiscal::PENDIENTE]);

        Queue::fake();

        Livewire::actingAs($this->usuarioConPermisos(['ventas.ver', 'ecf.gestionar']))
            ->test(ListVentas::class)
            ->callTableAction('reintentarEnvio', $venta->refresh())
            ->assertHasNoTableActionErrors();

        Queue::assertPushedOn('ecf', EnviarEcfJob::class, fn (EnviarEcfJob $job) => $job->venta->is($venta));
    }
}
