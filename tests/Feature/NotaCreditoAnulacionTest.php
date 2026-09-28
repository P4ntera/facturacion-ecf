<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Exceptions\VentaInvalidaException;
use App\Exceptions\VentaYaAnuladaException;
use App\Filament\Resources\VentaResource\Pages\ListVentas;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\Dgii\EcfBuilder;
use App\Services\ReporteService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Anulación de ventas según su situación fiscal: Nota de Crédito electrónica (e-CF 34) solo
 * para e-CF ya aceptados por la DGII; anulación interna simple para el resto; bloqueo mientras
 * la DGII no responda.
 */
class NotaCreditoAnulacionTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto, la cola sync ejecutaría EnviarEcfJob contra el gateway real.
        Queue::fake();

        $this->producto = Producto::create([
            'codigo' => 'NC-001',
            'nombre' => 'Producto NC',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    private function secuencia(TipoComprobante $tipo, string $prefijo, int $hasta = 1000): SecuenciaNcf
    {
        return SecuenciaNcf::create([
            'tipo_comprobante' => $tipo->value,
            'prefijo' => $prefijo,
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => $hasta,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    /** @param  array<string, mixed>  $datos */
    private function vender(array $datos, float $cantidad = 10): Venta
    {
        return app(VentaService::class)->registrar($datos + [
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad]],
        ], $this->empresaDefault);
    }

    /** Venta E31 que la DGII "aceptó" (se simula la respuesta del PAC). */
    private function ventaE31(EstadoFiscal $estadoFiscal = EstadoFiscal::ACEPTADO): Venta
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');

        $cliente = Cliente::create([
            'nombre' => 'Comercial RNC SRL',
            'tipo_documento' => TipoDocumentoCliente::RNC,
            'documento' => '130123456',
            'activo' => true,
        ]);

        $venta = $this->vender(['cliente_id' => $cliente->id, 'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value]);
        $venta->update(['estado_fiscal' => $estadoFiscal]);

        return $venta->fresh();
    }

    private function notasCredito(): int
    {
        return Venta::where('tipo_comprobante', TipoComprobante::NOTA_CREDITO)->count();
    }

    public function test_puede_anular_venta_sin_comprobante(): void
    {
        $this->empresaDefault->config()->update(['permite_ventas_sin_comprobante' => true]);
        $venta = $this->vender(['sin_comprobante' => true]);

        $anulada = app(VentaService::class)->anular($venta, 'Error del cajero');

        $this->assertSame(EstadoVenta::ANULADA, $anulada->estado);
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);
        $this->assertSame(0, $this->notasCredito());
        Queue::assertNotPushed(EnviarEcfJob::class);
    }

    public function test_puede_anular_venta_tipo_b_sin_nota_de_credito(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');
        $venta = $this->vender(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value]);

        $anulada = app(VentaService::class)->anular($venta, 'Devolución');

        $this->assertSame(EstadoVenta::ANULADA, $anulada->estado);
        $this->assertSame(EstadoFiscal::NO_APLICA, $anulada->estado_fiscal);
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);
        $this->assertSame(0, $this->notasCredito());
    }

    public function test_anular_ecf_aceptado_emite_nota_de_credito_34(): void
    {
        $original = $this->ventaE31();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');
        $this->assertEquals(90, (float) $this->producto->fresh()->stock);

        $anulada = app(VentaService::class)->anular($original, 'Mercancía devuelta');

        // La original queda anulada en el ERP pero sigue ACEPTADA para la DGII.
        $this->assertSame(EstadoVenta::ANULADA, $anulada->estado);
        $this->assertSame(EstadoFiscal::ACEPTADO, $anulada->estado_fiscal);

        $nota = Venta::where('tipo_comprobante', TipoComprobante::NOTA_CREDITO)->sole();
        $this->assertSame('E340000000001', $nota->ncf);
        $this->assertSame($original->ncf, $nota->ncf_modifica);
        $this->assertSame($original->id, $nota->venta_modificada_id);
        $this->assertSame($original->cliente_id, $nota->cliente_id);
        $this->assertSame((string) $original->total, (string) $nota->total);
        $this->assertSame((string) $original->total_itbis, (string) $nota->total_itbis);
        $this->assertSame(EstadoFiscal::PENDIENTE, $nota->estado_fiscal);
        $this->assertSame(EstadoVenta::EMITIDA, $nota->estado);
        $this->assertNull($nota->arqueo_caja_id);
        $this->assertCount(1, $nota->detalles);
        $this->assertSame('10.000', (string) $nota->detalles->first()->cantidad);

        Queue::assertPushed(EnviarEcfJob::class, fn (EnviarEcfJob $job) => $job->venta->is($nota));

        // Stock repuesto una sola vez (la nota no mueve inventario).
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);
    }

    public function test_el_ecf_de_la_nota_de_credito_referencia_la_venta_anulada(): void
    {
        $original = $this->ventaE31();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        app(VentaService::class)->anular($original, 'Mercancía devuelta');
        $nota = Venta::where('tipo_comprobante', TipoComprobante::NOTA_CREDITO)->sole();

        $ecf = app(EcfBuilder::class)->construir($nota)['ECF'];

        $this->assertSame('34', $ecf['Encabezado']['IdDoc']['TipoeCF']);
        $this->assertSame('0', $ecf['Encabezado']['IdDoc']['IndicadorNotaCredito']);
        $this->assertSame([
            'NCFModificado' => $original->ncf,
            'FechaNCFModificado' => $original->fecha->format('d-m-Y'),
            'CodigoModificacion' => '1',
            'RazonModificacion' => 'Mercancía devuelta',
        ], $ecf['InformacionReferencia']);
        $this->assertSame('130123456', $ecf['Encabezado']['Comprador']['RNCComprador']);
    }

    public function test_consumo_aceptado_como_rfce_tambien_requiere_nota_de_credito(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');
        $venta = $this->vender(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value]);
        $venta->update(['estado_fiscal' => EstadoFiscal::RFCE]);

        app(VentaService::class)->anular($venta, 'Error');

        $this->assertSame(1, $this->notasCredito());
    }

    public function test_no_puede_anular_ecf_pendiente(): void
    {
        $venta = $this->ventaE31(EstadoFiscal::PENDIENTE);
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        try {
            app(VentaService::class)->anular($venta, 'Muy pronto');
            $this->fail('Se anuló un e-CF que la DGII todavía no procesó.');
        } catch (VentaInvalidaException $e) {
            $this->assertStringContainsString('Espere a que la DGII', $e->getMessage());
        }

        $this->assertSame(EstadoVenta::EMITIDA, $venta->fresh()->estado);
        $this->assertEquals(90, (float) $this->producto->fresh()->stock);
        $this->assertSame(0, $this->notasCredito());
    }

    public function test_anular_ecf_rechazado_no_requiere_nota_de_credito(): void
    {
        $venta = $this->ventaE31(EstadoFiscal::RECHAZADO);

        $anulada = app(VentaService::class)->anular($venta, 'La DGII la rechazó');

        $this->assertSame(EstadoVenta::ANULADA, $anulada->estado);
        $this->assertSame(EstadoFiscal::RECHAZADO, $anulada->estado_fiscal);
        $this->assertSame(0, $this->notasCredito());
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);
    }

    public function test_no_puede_anular_una_venta_ya_anulada(): void
    {
        $original = $this->ventaE31();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');
        app(VentaService::class)->anular($original, 'Primera');

        try {
            app(VentaService::class)->anular($original, 'Segunda');
            $this->fail('Se anuló dos veces la misma venta.');
        } catch (VentaYaAnuladaException) {
        }

        $this->assertSame(1, $this->notasCredito());
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);
    }

    public function test_una_nota_de_credito_de_anulacion_no_se_anula(): void
    {
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');
        app(VentaService::class)->anular($this->ventaE31(), 'Devolución');
        $nota = Venta::where('tipo_comprobante', TipoComprobante::NOTA_CREDITO)->sole();
        $nota->update(['estado_fiscal' => EstadoFiscal::ACEPTADO]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('Nota de Crédito de anulación');

        app(VentaService::class)->anular($nota, 'Revivir la venta');
    }

    public function test_sin_secuencia_34_no_anula_nada(): void
    {
        $venta = $this->ventaE31();

        try {
            app(VentaService::class)->anular($venta, 'Sin secuencia');
            $this->fail('Se anuló un e-CF aceptado sin poder emitir su Nota de Crédito.');
        } catch (SecuenciaNcfAgotadaException $e) {
            $this->assertStringContainsString('34', $e->getMessage());
        }

        $this->assertSame(EstadoVenta::EMITIDA, $venta->fresh()->estado);
        $this->assertEquals(90, (float) $this->producto->fresh()->stock);
        $this->assertSame(0, $this->notasCredito());
    }

    public function test_anular_repone_el_stock_exacto(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);

        $venta = $this->vender(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value], 10);
        $this->assertEquals(90, (float) $this->producto->fresh()->stock);

        app(VentaService::class)->anular($venta, 'Devolución');
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);
    }

    /** El 607 conserva la venta aceptada y agrega la nota; los ingresos no cuentan ninguna. */
    public function test_607_incluye_venta_y_nota_y_los_ingresos_quedan_en_cero(): void
    {
        $original = $this->ventaE31();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');
        app(VentaService::class)->anular($original, 'Devolución');

        $desde = now()->startOfMonth();
        $hasta = now()->endOfMonth();

        $filas = app(ReporteService::class)->reporte607($desde, $hasta);
        $this->assertEqualsCanonicalizing([$original->ncf, 'E340000000001'], $filas->pluck('numero_comprobante')->all());
        $this->assertSame($original->ncf, $filas->firstWhere('numero_comprobante', 'E340000000001')['numero_comprobante_modificado']);

        $resumen = app(ReporteService::class)->ventasPorRango($desde, $hasta);
        $this->assertSame(0, $resumen['cantidad_ventas']);
        $this->assertEquals(0, (float) $resumen['total_vendido']);
    }

    public function test_la_accion_anular_requiere_permiso_y_no_aparece_en_la_nota(): void
    {
        $original = $this->ventaE31();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        foreach (['ventas.ver', 'ventas.anular'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $soloVer = Role::firstOrCreate(['empresa_id' => $this->empresaDefault->id, 'name' => 'Solo ver', 'guard_name' => 'web']);
        $soloVer->syncPermissions(['ventas.ver']);
        $sinPermiso = User::factory()->create();
        $sinPermiso->assignRole($soloVer);

        Livewire::actingAs($sinPermiso)
            ->test(ListVentas::class)
            ->assertTableActionHidden('anular', $original);

        $anulador = Role::firstOrCreate(['empresa_id' => $this->empresaDefault->id, 'name' => 'Anulador', 'guard_name' => 'web']);
        $anulador->syncPermissions(['ventas.ver', 'ventas.anular']);
        $conPermiso = User::factory()->create();
        $conPermiso->assignRole($anulador);

        Livewire::actingAs($conPermiso)
            ->test(ListVentas::class)
            ->callTableAction('anular', $original, data: ['motivo' => 'Devolución'])
            ->assertHasNoTableActionErrors();

        $nota = Venta::where('tipo_comprobante', TipoComprobante::NOTA_CREDITO)->sole();
        $this->assertTrue($original->fresh()->estaAnulada());

        Livewire::actingAs($conPermiso)
            ->test(ListVentas::class)
            ->assertTableActionHidden('anular', $nota);
    }
}
