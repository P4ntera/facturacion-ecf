<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Exceptions\VentaInvalidaException;
use App\Filament\Pages\PuntoDeVenta;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\ReporteService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Comprobantes tipo B (NCF físico, no electrónico): mismo VentaService::registrar(), mismo
 * cálculo de ITBIS y stock — la única diferencia es que nunca se transmite al PAC.
 */
class ComprobanteTipoBTest extends TestCase
{
    use RefreshDatabase;

    private function secuencia(TipoComprobante $tipo, string $prefijo, int $desde = 1, int $hasta = 1000): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => $tipo->value,
            'prefijo' => $prefijo,
            'secuencia_desde' => $desde,
            'secuencia_actual' => $desde,
            'secuencia_hasta' => $hasta,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function vendedor(): User
    {
        Permission::firstOrCreate(['name' => 'pos.acceder', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'facturacion.acceder', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['pos.acceder', 'facturacion.acceder']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function producto(string $codigo = 'TB-001', float $stock = 10): Producto
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
            'stock_minimo' => 1,
            'activo' => true,
        ]);
    }

    /** 1-2 del checklist: registrar una venta B02 completa. */
    public function test_registrar_venta_tipo_b02_genera_ncf_stock_itbis_y_no_dispara_ecf(): void
    {
        Queue::fake();

        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');

        $producto = $this->producto(stock: 10);
        $cliente = Cliente::create(['nombre' => 'Cliente B02', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
        ], $this->empresaDefault);

        // B01: B + 2 dígitos tipo + 8 dígitos secuencia = 11 caracteres.
        $this->assertSame('B0200000001', $venta->ncf);
        $this->assertSame(11, strlen($venta->ncf));
        $this->assertSame(200.00, (float) $venta->subtotal);
        $this->assertSame(36.00, (float) $venta->total_itbis);
        $this->assertSame(EstadoFiscal::NO_APLICA, $venta->estado_fiscal);
        $this->assertFalse($venta->esElectronica());

        $this->assertEquals(8, (float) $producto->fresh()->stock); // 10 - 2

        Queue::assertNotPushed(EnviarEcfJob::class);
    }

    /** 3 del checklist: E32 en la misma empresa sigue funcionando igual que antes. */
    public function test_registrar_venta_tipo_e32_en_empresa_con_ecf_si_dispara_el_job(): void
    {
        Queue::fake();

        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto();
        $cliente = Cliente::create(['nombre' => 'Cliente E32', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertSame('E320000000001', $venta->ncf);
        $this->assertSame(13, strlen($venta->ncf));
        $this->assertSame(EstadoFiscal::PENDIENTE, $venta->estado_fiscal);
        $this->assertTrue($venta->esElectronica());

        Queue::assertPushed(EnviarEcfJob::class);
    }

    /** 4 del checklist: un tipo de COMPRA (no de venta) se rechaza, exista o no su secuencia. */
    public function test_no_permite_registrar_venta_con_tipo_de_compra(): void
    {
        $producto = $this->producto();
        $cliente = Cliente::create(['nombre' => 'Cliente', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    /** Formato de NCF físico para un tipo distinto de B02, para no sobreajustar solo a Consumo. */
    public function test_formato_ncf_fisico_b01_tiene_11_caracteres(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA, 'B01');

        $producto = $this->producto();
        $cliente = Cliente::create(['nombre' => 'Cliente RNC', 'documento' => '130123456', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertSame('B0100000001', $venta->ncf);
        $this->assertSame(11, strlen($venta->ncf));
    }

    /** El selector del POS filtra tipos de compra siempre, y electrónicos solo con e-CF. */
    public function test_pos_solo_ofrece_tipos_de_venta_y_electronicos_solo_con_ecf(): void
    {
        $vendedor = $this->vendedor();

        $this->empresaDefault->update(['usa_ecf' => false]);

        $tiposSinEcf = Livewire::actingAs($vendedor)->test(PuntoDeVenta::class)->instance()->tiposComprobante();

        $this->assertArrayHasKey(TipoComprobante::FACTURA_CONSUMO_FISICA->value, $tiposSinEcf);
        $this->assertArrayNotHasKey(TipoComprobante::FACTURA_CONSUMO->value, $tiposSinEcf);
        $this->assertArrayNotHasKey(TipoComprobante::COMPRAS->value, $tiposSinEcf);

        $this->empresaDefault->update(['usa_ecf' => true]);

        $tiposConEcf = Livewire::actingAs($vendedor)->test(PuntoDeVenta::class)->instance()->tiposComprobante();

        $this->assertArrayHasKey(TipoComprobante::FACTURA_CONSUMO_FISICA->value, $tiposConEcf);
        $this->assertArrayHasKey(TipoComprobante::FACTURA_CONSUMO->value, $tiposConEcf);
        $this->assertArrayNotHasKey(TipoComprobante::COMPRAS->value, $tiposConEcf);
    }

    /** El 607 y el desglose del dashboard incluyen comprobantes físicos, no solo electrónicos. */
    public function test_reporte_607_y_desglose_incluyen_ventas_fisicas(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $productoA = $this->producto('TB-FIS');
        $productoB = $this->producto('TB-ELEC');
        $cliente = Cliente::create(['nombre' => 'Cliente reporte', 'activo' => true]);

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'lineas' => [['producto_id' => $productoA->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $productoB->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $desde = now()->startOfMonth();
        $hasta = now()->endOfMonth();

        $reporte607 = app(ReporteService::class)->reporte607($desde, $hasta);
        $this->assertCount(2, $reporte607);

        $desglose = app(ReporteService::class)->desgloseComprobantes($desde, $hasta);
        $this->assertSame(1, $desglose['electronicos']);
        $this->assertSame(1, $desglose['fisicos']);
    }
}
