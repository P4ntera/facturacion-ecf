<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoOrdenCompra;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Models\Empresa;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\OrdenCompraService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrdenCompraTest extends TestCase
{
    use RefreshDatabase;

    private OrdenCompraService $service;
    private Empresa $empresa;
    private User $admin;
    private Proveedor $proveedor;
    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->empresa = $this->empresaDefault;
        $this->admin = User::factory()->create(['empresa_id' => $this->empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($this->admin, $this->empresa);
        $this->actingAs($this->admin);

        $this->proveedor = Proveedor::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Proveedor Test',
            'tipo' => TipoProveedor::FORMAL,
            'rnc' => '123456789',
            'activo' => true,
        ]);

        $this->producto = Producto::create([
            'empresa_id' => $this->empresa->id,
            'codigo' => 'PROD-001',
            'nombre' => 'Producto Test',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 100,
            'precio' => 150,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 0,
            'activo' => true,
        ]);

        $this->service = app(OrdenCompraService::class);
    }

    private function datosOrden(array $override = []): array
    {
        return array_merge([
            'proveedor_id' => $this->proveedor->id,
            'fecha' => now()->toDateString(),
            'fecha_esperada' => now()->addDays(7)->toDateString(),
            'notas' => null,
            'lineas' => [
                [
                    'producto_id' => $this->producto->id,
                    'cantidad_solicitada' => 10,
                    'precio_unitario' => 100,
                ],
            ],
        ], $override);
    }

    public function test_crear_orden_compra_con_detalles(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);

        $this->assertDatabaseHas('ordenes_compra', [
            'id' => $orden->id,
            'empresa_id' => $this->empresa->id,
            'proveedor_id' => $this->proveedor->id,
            'estado' => 'borrador',
        ]);

        $this->assertCount(1, $orden->detalles);
        $this->assertEquals('1000.00', $orden->subtotal);
        $this->assertEquals('180.00', $orden->itbis);
        $this->assertEquals('1180.00', $orden->total);
    }

    public function test_numero_correlativo_por_empresa(): void
    {
        $orden1 = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $orden2 = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);

        $this->assertEquals('OC-00001', $orden1->numero);
        $this->assertEquals('OC-00002', $orden2->numero);
    }

    public function test_recepcion_parcial_aumenta_stock(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $detalle = $orden->detalles->first();

        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 4],
        ], $this->empresa);

        $this->producto->refresh();
        $this->assertEquals(4, (float) $this->producto->stock);
    }

    public function test_recepcion_parcial_actualiza_estado(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $detalle = $orden->detalles->first();

        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 4],
        ], $this->empresa);

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::RECEPCION_PARCIAL, $orden->estado);
    }

    public function test_recepcion_total_completa_orden(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $detalle = $orden->detalles->first();

        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 10],
        ], $this->empresa);

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::COMPLETADA, $orden->estado);
    }

    public function test_no_recibir_mas_de_lo_solicitado(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $detalle = $orden->detalles->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pendiente');

        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 15],
        ], $this->empresa);
    }

    public function test_multiples_recepciones_acumulan_cantidad(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $detalle = $orden->detalles->first();

        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 3],
        ], $this->empresa);

        $orden->refresh();
        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 7],
        ], $this->empresa);

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::COMPLETADA, $orden->estado);
        $this->producto->refresh();
        $this->assertEquals(10, (float) $this->producto->stock);
    }

    public function test_cancelar_orden_en_borrador(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);

        $this->service->cancelar($orden);

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::CANCELADA, $orden->estado);
    }

    public function test_no_cancelar_orden_con_recepciones(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $detalle = $orden->detalles->first();
        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 3],
        ], $this->empresa);

        $orden->refresh();
        $this->expectException(RuntimeException::class);
        $this->service->cancelar($orden);
    }

    public function test_porcentaje_recibido_calcula_correctamente(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $this->assertEquals(0.0, $orden->porcentajeRecibido());

        $detalle = $orden->detalles->first();
        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 5],
        ], $this->empresa);

        $orden->load('detalles');
        $this->assertEquals(50.0, $orden->porcentajeRecibido());
    }

    public function test_kardex_registra_entrada_por_recepcion(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        $detalle = $orden->detalles->first();
        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $detalle->id, 'cantidad_recibida' => 5],
        ], $this->empresa);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $this->producto->id,
            'tipo' => 'entrada',
            'origen' => OrigenMovimiento::RECEPCION_ORDEN_COMPRA->value,
            'cantidad' => 5,
        ]);
    }

    public function test_aislamiento_empresa_id(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);

        $otraEmpresa = Empresa::factory()->create();
        app(RolesEmpresaService::class)->sembrarRolesBase($otraEmpresa);

        $this->assertEquals($this->empresa->id, $orden->empresa_id);

        $this->expectException(RuntimeException::class);
        $this->service->registrarRecepcion($orden, [], $otraEmpresa);
    }

    public function test_no_recibir_en_borrador(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no está en un estado');

        $this->service->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => 1, 'cantidad_recibida' => 1],
        ], $this->empresa);
    }

    public function test_crear_sin_lineas_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('al menos una línea');

        $this->service->crear($this->datosOrden(['lineas' => []]), $this->admin->id, $this->empresa);
    }
}
