<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\VentaInvalidaException;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Pages\PuntoDeVentaTouch;
use App\Filament\Resources\VentaResource\Pages\ListVentas;
use App\Jobs\EnviarEcfJob;
use App\Models\Caja;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\ReporteService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ventas al portador (sin cliente) y ventas sin comprobante fiscal (sin NCF, opt-in por empresa
 * vía EmpresaConfiguracion::permite_ventas_sin_comprobante).
 */
class VentaAlPortadorTest extends TestCase
{
    use RefreshDatabase;

    private function permitirSinComprobante(bool $permitir = true): void
    {
        $this->empresaDefault->config()->update(['permite_ventas_sin_comprobante' => $permitir]);
    }

    private function secuencia(TipoComprobante $tipo, string $prefijo): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => $tipo->value,
            'prefijo' => $prefijo,
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function producto(float $stock = 10, string $codigo = 'AP-001'): Producto
    {
        return Producto::create([
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => $stock,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    private function usuario(array $permisos = ['pos.acceder', 'facturacion.acceder']): User
    {
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $rol = Role::firstOrCreate(['empresa_id' => $this->empresaDefault->id, 'name' => 'Rol-portador', 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    /** @param  array<string, mixed>  $datos */
    private function registrar(array $datos, float $cantidad = 1, ?Producto $producto = null): Venta
    {
        $producto ??= $this->producto();

        return app(VentaService::class)->registrar($datos + [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
        ], $this->empresaDefault);
    }

    public function test_puede_registrar_venta_sin_cliente_y_sin_comprobante(): void
    {
        Queue::fake();
        $this->permitirSinComprobante();
        $producto = $this->producto(stock: 10);

        $venta = $this->registrar(['sin_comprobante' => true], 2, $producto);

        $this->assertNull($venta->cliente_id);
        $this->assertNull($venta->tipo_comprobante);
        $this->assertNull($venta->ncf);
        $this->assertSame(EstadoFiscal::NO_APLICA, $venta->estado_fiscal);
        $this->assertSame('236.00', (string) $venta->total);
        $this->assertSame('Al portador', $venta->nombreCliente());
        $this->assertSame('Sin comprobante', $venta->etiquetaComprobante());
        $this->assertEquals(8, (float) $producto->fresh()->stock);
        Queue::assertNotPushed(EnviarEcfJob::class);
    }

    public function test_venta_sin_comprobante_requiere_que_la_empresa_lo_permita(): void
    {
        $this->permitirSinComprobante(false);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('ventas sin comprobante');

        $this->registrar(['sin_comprobante' => true]);
    }

    public function test_puede_registrar_venta_al_portador_con_b02(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');

        $venta = $this->registrar(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value]);

        $this->assertNull($venta->cliente_id);
        $this->assertSame('B0200000001', $venta->ncf);
    }

    public function test_e32_al_portador_despacha_el_ecf(): void
    {
        Queue::fake();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $venta = $this->registrar(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value]);

        $this->assertNull($venta->cliente_id);
        $this->assertSame(EstadoFiscal::PENDIENTE, $venta->estado_fiscal);
        Queue::assertPushed(EnviarEcfJob::class);
    }

    public function test_b02_al_portador_sobre_250k_falla(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('requiere seleccionar un cliente con RNC/Cédula');

        // 3000 x RD$100 + 18% ITBIS = RD$354,000.
        $this->registrar(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value], 3000, $this->producto(stock: 5000));
    }

    public function test_b01_sin_cliente_falla(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA, 'B01');

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('requiere seleccionar un cliente con RNC/Cédula');

        $this->registrar(['tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA->value]);
    }

    public function test_b15_gubernamental_sin_cliente_falla(): void
    {
        $this->secuencia(TipoComprobante::GUBERNAMENTAL_FISICA, 'B15');

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('requiere seleccionar un cliente');

        $this->registrar(['tipo_comprobante' => TipoComprobante::GUBERNAMENTAL_FISICA->value]);
    }

    public function test_venta_a_credito_al_portador_falla(): void
    {
        $this->permitirSinComprobante();

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('crédito requiere seleccionar un cliente');

        $this->registrar(['sin_comprobante' => true, 'tipo_pago' => TipoPago::CREDITO->value]);
    }

    public function test_607_excluye_ventas_sin_comprobante_e_incluye_b02_al_portador(): void
    {
        $this->permitirSinComprobante();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');

        $this->registrar(['sin_comprobante' => true], producto: $this->producto(codigo: 'AP-SIN'));
        $this->registrar(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value], producto: $this->producto(codigo: 'AP-B02'));

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(['B0200000001'], $filas->pluck('numero_comprobante')->all());
        $this->assertNull($filas->first()['rnc_cedula']);
        $this->assertNull($filas->first()['tipo_identificacion']);
    }

    public function test_pos_muestra_al_portador_y_solo_ofrece_sin_comprobante_si_esta_permitido(): void
    {
        $usuario = $this->usuario();

        $sinPermiso = Livewire::actingAs($usuario)->test(PuntoDeVenta::class)->call('abrirCaja', '500.00')->assertSee('Al portador');
        $this->assertArrayNotHasKey(PuntoDeVenta::SIN_COMPROBANTE, $sinPermiso->instance()->tiposComprobante());

        $this->permitirSinComprobante();

        $conPermiso = Livewire::actingAs($usuario)->test(PuntoDeVenta::class);
        $this->assertArrayHasKey(PuntoDeVenta::SIN_COMPROBANTE, $conPermiso->instance()->tiposComprobante());
    }

    public function test_pos_normal_cobra_sin_cliente_ni_comprobante(): void
    {
        Queue::fake();
        $this->permitirSinComprobante();
        $producto = $this->producto();

        Livewire::actingAs($this->usuario())
            ->test(PuntoDeVenta::class)
            ->call('abrirCaja', '500.00')
            ->set('tipoComprobante', PuntoDeVenta::SIN_COMPROBANTE)
            ->call('agregarProducto', $producto->id)
            ->call('cobrar')
            ->assertSet('carrito', []);

        $venta = Venta::sole();
        $this->assertNull($venta->cliente_id);
        $this->assertNull($venta->ncf);
        Queue::assertNotPushed(EnviarEcfJob::class);
    }

    public function test_pos_bloquea_b01_sin_cliente(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA, 'B01');
        $producto = $this->producto();

        $componente = Livewire::actingAs($this->usuario())
            ->test(PuntoDeVenta::class)
            ->call('abrirCaja', '500.00')
            ->set('tipoComprobante', TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA->value)
            ->call('agregarProducto', $producto->id);

        $this->assertFalse($componente->instance()->puedeCobrar());
        $componente->assertSee('requiere seleccionar un cliente con RNC/Cédula');

        $componente->call('cobrar');
        $this->assertSame(0, Venta::count());
    }

    public function test_pos_tactil_usa_sin_comprobante_por_defecto_solo_si_esta_permitido(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $usuario = $this->usuario(['pos.acceder']);

        Livewire::actingAs($usuario)
            ->test(PuntoDeVentaTouch::class)
            ->assertSet('tipoComprobante', TipoComprobante::FACTURA_CONSUMO->value);

        $this->permitirSinComprobante();
        $caja = Caja::create(['nombre' => 'Caja 1', 'activo' => true]);
        $producto = $this->producto();

        Livewire::actingAs($usuario)
            ->test(PuntoDeVentaTouch::class)
            ->assertSet('tipoComprobante', PuntoDeVenta::SIN_COMPROBANTE)
            ->call('seleccionarCaja', $caja->id)
            ->call('abrirCaja', '500')
            ->call('tocarProducto', $producto->id, '1')
            ->call('cobrar')
            ->assertDispatched('venta-cobrada');

        $venta = Venta::sole();
        $this->assertNull($venta->ncf);
        $this->assertNull($venta->cliente_id);
        $this->assertSame($caja->id, $venta->caja_id);
    }

    public function test_listado_muestra_al_portador_y_filtra_sin_comprobante(): void
    {
        $this->permitirSinComprobante();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');

        $sinComprobante = $this->registrar(['sin_comprobante' => true], producto: $this->producto(codigo: 'AP-SIN'));
        $conB02 = $this->registrar(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value], producto: $this->producto(codigo: 'AP-B02'));

        Livewire::actingAs($this->usuario(['ventas.ver']))
            ->test(ListVentas::class)
            ->assertSee('Al portador')
            ->filterTable('tipo_comprobante', 'sin_comprobante')
            ->assertCanSeeTableRecords([$sinComprobante])
            ->assertCanNotSeeTableRecords([$conB02])
            ->resetTableFilters()
            ->filterTable('al_portador', true)
            ->assertCanSeeTableRecords([$sinComprobante, $conB02]);
    }
}
