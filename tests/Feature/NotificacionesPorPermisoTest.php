<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoNotificacion;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Filament\Pages\MisNotificaciones;
use App\Filament\Widgets\AlertasWidget;
use App\Jobs\EnviarEcfJob;
use App\Models\Empresa;
use App\Models\PreferenciaNotificacion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\CompraService;
use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\EnvioEcfService;
use App\Services\Dgii\RespuestaEcf;
use App\Services\RolesEmpresaService;
use App\Services\SecuenciaNcfService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GatewayStub;
use Tests\TestCase;

/**
 * Notificaciones controladas por permiso (notificaciones.*): el rol habilita, la preferencia
 * personal silencia, y nunca cruzan de empresa.
 */
class NotificacionesPorPermisoTest extends TestCase
{
    use RefreshDatabase;

    private User $administrador;

    private User $almacenista;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        foreach (['administrador' => 'Administrador', 'almacenista' => 'Almacenista', 'vendedor' => 'Vendedor'] as $propiedad => $rol) {
            $this->{$propiedad} = User::factory()->create(['empresa_id' => $this->empresaDefault->id]);
            $this->{$propiedad}->assignRole($rol);
        }
    }

    private function bajarStockDelMinimo(): Producto
    {
        $producto = Producto::create([
            'codigo' => 'NOT-001', 'nombre' => 'Arroz', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 5, 'precio' => 10, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 10, 'stock_minimo' => 5, 'activo' => true,
        ]);

        $producto->update(['stock' => 3]);

        return $producto;
    }

    /** Cuenta por pagar vencida de $empresa (vía CompraService, como en producción). */
    private function cxpVencida(Empresa $empresa, string $codigo): void
    {
        $proveedor = Proveedor::factory()->create(['empresa_id' => $empresa->id]);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'codigo' => $codigo, 'nombre' => "Insumo {$codigo}", 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 100, 'precio' => 150, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 100, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'tipo_pago' => TipoPago::CREDITO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 100]],
        ], $this->administrador->id, $empresa);

        $compra->cuentaPorPagar->update(['fecha_vencimiento' => now()->subDay()]);
    }

    private function notificaciones(User $usuario): int
    {
        return $usuario->fresh()->notifications()->count();
    }

    public function test_los_roles_base_reciben_los_permisos_de_notificacion_esperados(): void
    {
        foreach (TipoNotificacion::cases() as $tipo) {
            $this->assertTrue($this->administrador->can($tipo->permiso()), $tipo->permiso());
            $this->assertFalse($this->vendedor->can($tipo->permiso()), $tipo->permiso());
        }

        $this->assertTrue($this->almacenista->can('notificaciones.stock_bajo'));
        $this->assertFalse($this->almacenista->can('notificaciones.ncf_agotandose'));
    }

    public function test_stock_bajo_solo_llega_a_quien_tiene_el_permiso(): void
    {
        $this->bajarStockDelMinimo();

        $this->assertSame(1, $this->notificaciones($this->administrador));
        $this->assertSame(1, $this->notificaciones($this->almacenista));
        $this->assertSame(0, $this->notificaciones($this->vendedor));
    }

    public function test_un_usuario_puede_silenciar_una_notificacion_que_su_rol_permite(): void
    {
        PreferenciaNotificacion::create(['user_id' => $this->administrador->id, 'tipo' => TipoNotificacion::STOCK_BAJO, 'activa' => false]);

        $this->bajarStockDelMinimo();

        $this->assertSame(0, $this->notificaciones($this->administrador));
        $this->assertSame(1, $this->notificaciones($this->almacenista));
        $this->assertFalse($this->administrador->recibeNotificacion(TipoNotificacion::STOCK_BAJO));
    }

    public function test_no_llega_a_usuarios_de_otra_empresa_aunque_tengan_el_permiso(): void
    {
        $otra = Empresa::factory()->create();
        app(RolesEmpresaService::class)->sembrarRolesBase($otra);
        $adminOtra = User::factory()->create(['empresa_id' => $otra->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($adminOtra, $otra);

        $this->bajarStockDelMinimo();

        $this->assertSame(1, $this->notificaciones($this->administrador));
        $this->assertSame(0, $this->notificaciones($adminOtra));
    }

    public function test_ncf_agotandose_llega_al_administrador_y_no_al_almacenista(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value, 'prefijo' => 'E32',
            'secuencia_desde' => 1, 'secuencia_actual' => 1, 'secuencia_hasta' => 10,
            'vencimiento' => now()->addYear(), 'activa' => true,
        ]);

        app(SecuenciaNcfService::class)->siguiente(TipoComprobante::FACTURA_CONSUMO, $this->empresaDefault);

        $this->assertSame(1, $this->notificaciones($this->administrador));
        $this->assertSame(0, $this->notificaciones($this->almacenista));
    }

    public function test_ecf_rechazado_avisa_una_sola_vez(): void
    {
        // Solo el envío e-CF: la notificación de Filament también se encola y debe entregarse.
        Queue::fake([EnviarEcfJob::class]);

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value, 'prefijo' => 'E32',
            'secuencia_desde' => 1, 'secuencia_actual' => 1, 'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(), 'activa' => true,
        ]);

        $producto = Producto::create([
            'codigo' => 'NOT-ECF', 'nombre' => 'Producto', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 5, 'precio' => 10, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function enviar(array $ecf): RespuestaEcf
            {
                return new RespuestaEcf(exito: true, estado: 'Rechazado', pacId: 'pac-1', errorMessage: 'Monto inválido');
            }

            public function consultarTrack(string $pacId): RespuestaEcf
            {
                return new RespuestaEcf(exito: true, estado: 'Rechazado', pacId: 'pac-1', errorMessage: 'Monto inválido');
            }
        });

        app(EnvioEcfService::class)->enviar($venta);
        app(EnvioEcfService::class)->refrescarEstado($venta->fresh());

        $this->assertSame(1, $this->notificaciones($this->administrador));
        $this->assertSame(0, $this->notificaciones($this->almacenista));
        $this->assertStringContainsString($venta->ncf, $this->administrador->notifications()->first()->data['title']);
    }

    public function test_el_dashboard_solo_muestra_las_alertas_que_el_rol_permite(): void
    {
        $this->bajarStockDelMinimo();

        $this->cxpVencida($this->empresaDefault, 'CXP-A');

        // Vendedor: tiene productos.ver y cxc.ver, pero ningún permiso de notificación.
        $this->actingAs($this->vendedor);
        $this->assertFalse(AlertasWidget::canView());

        $this->actingAs($this->almacenista);
        $this->assertTrue(AlertasWidget::canView());
        $titulos = (new AlertasWidget)->getAlertas()->pluck('titulo');
        $this->assertContains('Stock bajo mínimo', $titulos);
        $this->assertNotContains('Cuentas por pagar vencidas', $titulos);

        $this->actingAs($this->administrador);
        $titulos = (new AlertasWidget)->getAlertas()->pluck('titulo');
        $this->assertContains('Stock bajo mínimo', $titulos);
        $this->assertContains('Cuentas por pagar vencidas', $titulos);
    }

    public function test_el_dashboard_no_muestra_alertas_de_otra_empresa(): void
    {
        $otra = Empresa::factory()->create();

        Producto::create([
            'empresa_id' => $otra->id, 'codigo' => 'OTRA-1', 'nombre' => 'Ajeno', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 5, 'precio' => 10, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 0, 'stock_minimo' => 5, 'activo' => true,
        ]);

        $this->cxpVencida($otra, 'CXP-OTRA');

        Venta::query()->update(['estado_fiscal' => 'rechazado']);

        $this->actingAs($this->administrador);

        $this->assertSame([], (new AlertasWidget)->getAlertas()->all());
    }

    public function test_mis_notificaciones_lista_solo_lo_que_el_rol_permite_y_guarda_la_preferencia(): void
    {
        Livewire::actingAs($this->almacenista)
            ->test(MisNotificaciones::class)
            ->assertSchemaStateSet(['stock_bajo' => true], 'form')
            ->assertFormFieldExists('stock_bajo')
            ->assertFormFieldDoesNotExist('ncf_agotandose')
            ->fillForm(['stock_bajo' => false, 'ncf_agotandose' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->almacenista->recibeNotificacion(TipoNotificacion::STOCK_BAJO));
        $this->assertDatabaseMissing('preferencias_notificacion', ['user_id' => $this->almacenista->id, 'tipo' => 'ncf_agotandose']);

        $this->actingAs($this->vendedor)
            ->get(MisNotificaciones::getUrl())
            ->assertForbidden();
    }
}
