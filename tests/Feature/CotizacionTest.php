<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoCotizacion;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Empresa;
use App\Models\EmpresaConfiguracion;
use App\Models\Producto;
use App\Models\User;
use App\Services\CotizacionService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CotizacionTest extends TestCase
{
    use RefreshDatabase;

    private CotizacionService $service;
    private Empresa $empresa;
    private User $admin;
    private Producto $producto;
    private ?Cliente $cliente = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->empresa = $this->empresaDefault;
        $this->admin = User::factory()->create(['empresa_id' => $this->empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($this->admin, $this->empresa);
        $this->actingAs($this->admin);

        $this->producto = Producto::create([
            'empresa_id' => $this->empresa->id,
            'codigo' => 'PROD-001',
            'nombre' => 'Producto Test',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 80,
            'precio' => 150,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 50,
            'activo' => true,
        ]);

        $this->cliente = Cliente::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Cliente Test',
            'activo' => true,
        ]);

        $this->service = app(CotizacionService::class);
    }

    private function datosCotizacion(array $override = []): array
    {
        return array_merge([
            'cliente_id' => $this->cliente->id,
            'fecha' => now()->toDateString(),
            'dias_vigencia' => 15,
            'condiciones_pago' => 'Contado',
            'notas' => null,
            'lineas' => [
                [
                    'producto_id' => $this->producto->id,
                    'cantidad' => 5,
                    'precio_unitario' => 150,
                    'descuento' => 0,
                ],
            ],
        ], $override);
    }

    public function test_crear_cotizacion_con_detalles(): void
    {
        $cotizacion = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);

        $this->assertDatabaseHas('cotizaciones', [
            'id' => $cotizacion->id,
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'estado' => 'borrador',
        ]);

        $this->assertCount(1, $cotizacion->detalles);
        $this->assertEquals('750.00', $cotizacion->subtotal);
        $this->assertEquals('135.00', $cotizacion->itbis);
        $this->assertEquals('885.00', $cotizacion->total);
    }

    public function test_numero_correlativo_por_empresa(): void
    {
        $cot1 = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);
        $cot2 = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);

        $this->assertEquals('COT-00001', $cot1->numero);
        $this->assertEquals('COT-00002', $cot2->numero);
    }

    public function test_cotizacion_no_descuenta_stock(): void
    {
        $stockAntes = (float) $this->producto->stock;

        $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);

        $this->producto->refresh();
        $this->assertEquals($stockAntes, (float) $this->producto->stock);
    }

    public function test_cotizacion_no_consume_ncf(): void
    {
        $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);

        $this->assertDatabaseMissing('ventas', [
            'empresa_id' => $this->empresa->id,
        ]);
    }

    public function test_convertir_cotizacion_aprobada_a_venta(): void
    {
        EmpresaConfiguracion::updateOrCreate(
            ['empresa_id' => $this->empresa->id],
            ['permite_ventas_sin_comprobante' => true],
        );

        $cotizacion = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);
        $cotizacion->update([
            'estado' => EstadoCotizacion::APROBADA,
            'aprobado_por' => $this->admin->id,
            'aprobado_en' => now(),
        ]);

        $venta = $this->service->convertirAVenta($cotizacion, $this->empresa, [
            'sin_comprobante' => true,
        ]);

        $this->assertNotNull($venta->id);
        $cotizacion->refresh();
        $this->assertEquals(EstadoCotizacion::FACTURADA, $cotizacion->estado);
        $this->assertEquals($venta->id, $cotizacion->venta_id);
    }

    public function test_no_convertir_si_no_aprobada(): void
    {
        $cotizacion = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('debe estar aprobada');

        $this->service->convertirAVenta($cotizacion, $this->empresa);
    }

    public function test_no_convertir_si_ya_facturada(): void
    {
        EmpresaConfiguracion::updateOrCreate(
            ['empresa_id' => $this->empresa->id],
            ['permite_ventas_sin_comprobante' => true],
        );

        $cotizacion = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);
        $cotizacion->update(['estado' => EstadoCotizacion::APROBADA]);

        $this->service->convertirAVenta($cotizacion, $this->empresa, ['sin_comprobante' => true]);

        $cotizacion->refresh();
        $this->expectException(RuntimeException::class);
        $this->service->convertirAVenta($cotizacion, $this->empresa, ['sin_comprobante' => true]);
    }

    public function test_venta_creada_tiene_mismos_productos(): void
    {
        EmpresaConfiguracion::updateOrCreate(
            ['empresa_id' => $this->empresa->id],
            ['permite_ventas_sin_comprobante' => true],
        );

        $cotizacion = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);
        $cotizacion->update(['estado' => EstadoCotizacion::APROBADA]);

        $venta = $this->service->convertirAVenta($cotizacion, $this->empresa, ['sin_comprobante' => true]);
        $venta->load('detalles');

        $this->assertCount(1, $venta->detalles);
        $this->assertEquals($this->producto->id, $venta->detalles->first()->producto_id);
    }

    public function test_cotizacion_se_vence_automaticamente(): void
    {
        $cotizacion = $this->service->crear(
            $this->datosCotizacion(['dias_vigencia' => 1, 'fecha' => now()->subDays(5)->toDateString()]),
            $this->admin->id,
            $this->empresa,
        );

        $this->assertEquals(EstadoCotizacion::BORRADOR, $cotizacion->estado);

        $vencidas = $this->service->vencerExpiradas();

        $this->assertEquals(1, $vencidas);
        $cotizacion->refresh();
        $this->assertEquals(EstadoCotizacion::VENCIDA, $cotizacion->estado);
    }

    public function test_duplicar_cotizacion(): void
    {
        $original = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);

        $duplicada = $this->service->duplicar($original, $this->empresa);

        $this->assertNotEquals($original->id, $duplicada->id);
        $this->assertNotEquals($original->numero, $duplicada->numero);
        $this->assertEquals(EstadoCotizacion::BORRADOR, $duplicada->estado);
        $this->assertCount(1, $duplicada->detalles);
        $this->assertEquals($original->detalles->first()->producto_id, $duplicada->detalles->first()->producto_id);
    }

    public function test_aislamiento_empresa_id(): void
    {
        $cotizacion = $this->service->crear($this->datosCotizacion(), $this->admin->id, $this->empresa);

        $otraEmpresa = Empresa::factory()->create();
        app(RolesEmpresaService::class)->sembrarRolesBase($otraEmpresa);

        $this->assertEquals($this->empresa->id, $cotizacion->empresa_id);

        $this->expectException(RuntimeException::class);
        $this->service->convertirAVenta($cotizacion, $otraEmpresa);
    }

    public function test_crear_sin_lineas_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('al menos una línea');

        $this->service->crear($this->datosCotizacion(['lineas' => []]), $this->admin->id, $this->empresa);
    }

    public function test_fecha_vencimiento_se_calcula(): void
    {
        $cotizacion = $this->service->crear(
            $this->datosCotizacion(['dias_vigencia' => 30]),
            $this->admin->id,
            $this->empresa,
        );

        $esperada = now()->addDays(30)->toDateString();
        $this->assertEquals($esperada, $cotizacion->fecha_vencimiento->toDateString());
    }
}
