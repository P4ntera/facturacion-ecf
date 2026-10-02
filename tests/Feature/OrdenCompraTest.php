<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoOrdenCompra;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\RecepcionCompra;
use App\Models\User;
use App\Services\CompraService;
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

    /**
     * Recibir mercancía de una orden ES registrar la compra (con su factura): la compra mueve el
     * stock y anota lo recibido en la orden.
     */
    private function recibir(OrdenCompra $orden, float $cantidad, string $ncf = 'B0100000001', ?array $lineasExtra = null): Compra
    {
        return app(CompraService::class)->crear([
            'proveedor_id' => $this->proveedor->id,
            'orden_compra_id' => $orden->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
            'ncf' => $ncf,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $this->producto->id, 'cantidad' => $cantidad, 'costo_unitario' => 100],
                ...($lineasExtra ?? []),
            ],
        ], $this->admin->id, $this->empresa);
    }

    private function ordenEnviada(): OrdenCompra
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);
        $this->service->aprobar($orden);

        return $orden->refresh();
    }

    public function test_recepcion_parcial_aumenta_stock(): void
    {
        $orden = $this->ordenEnviada();

        $this->recibir($orden, 4);

        $this->producto->refresh();
        $this->assertEquals(4, (float) $this->producto->stock);
    }

    public function test_recepcion_parcial_actualiza_estado(): void
    {
        $orden = $this->ordenEnviada();

        $this->recibir($orden, 4);

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::RECEPCION_PARCIAL, $orden->estado);
    }

    public function test_recepcion_total_completa_orden(): void
    {
        $orden = $this->ordenEnviada();

        $this->recibir($orden, 10);

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::COMPLETADA, $orden->estado);
    }

    public function test_no_recibir_mas_de_lo_solicitado_y_no_se_crea_la_compra(): void
    {
        $orden = $this->ordenEnviada();

        try {
            $this->recibir($orden, 15);
            $this->fail('Debió rechazar recibir más de lo pendiente.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pendiente', $e->getMessage());
        }

        // Se revierte todo: ni compra, ni stock, ni cantidad recibida.
        $this->assertSame(0, Compra::count());
        $this->assertEquals(0, (float) $this->producto->fresh()->stock);
        $this->assertEquals(0, (float) $orden->detalles()->first()->cantidad_recibida);
    }

    public function test_multiples_recepciones_acumulan_cantidad(): void
    {
        $orden = $this->ordenEnviada();

        $this->recibir($orden, 3, 'B0100000001');
        $this->recibir($orden, 7, 'B0100000002');

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::COMPLETADA, $orden->estado);
        $this->producto->refresh();
        $this->assertEquals(10, (float) $this->producto->stock);
        $this->assertSame(2, $orden->compras()->count());
    }

    public function test_recibir_crea_la_compra_ligada_a_la_orden_y_a_su_recepcion(): void
    {
        $orden = $this->ordenEnviada();

        $compra = $this->recibir($orden, 4, 'B0100000077');

        $this->assertSame($orden->id, $compra->orden_compra_id);
        $this->assertSame('B0100000077', $compra->ncf);

        $recepcion = RecepcionCompra::where('orden_compra_id', $orden->id)->sole();
        $this->assertSame($compra->id, $recepcion->compra_id);
        $this->assertEquals(4, (float) $recepcion->detalles()->sole()->cantidad_recibida);
    }

    public function test_el_stock_entra_una_sola_vez(): void
    {
        $orden = $this->ordenEnviada();

        $this->recibir($orden, 4);

        // Un único movimiento de entrada, el de la compra (antes la recepción y la compra
        // metían la misma mercancía dos veces).
        $this->assertSame(1, MovimientoInventario::where('producto_id', $this->producto->id)->count());
        $this->assertEquals(4, (float) $this->producto->fresh()->stock);
    }

    public function test_un_producto_que_no_estaba_en_la_orden_entra_como_linea_extra(): void
    {
        $orden = $this->ordenEnviada();
        $extra = Producto::create([
            'empresa_id' => $this->empresa->id, 'codigo' => 'EXTRA', 'nombre' => 'Producto extra',
            'tipo' => TipoProducto::PRODUCTO, 'costo' => 10, 'precio' => 20, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 0, 'activo' => true,
        ]);

        $this->recibir($orden, 4, lineasExtra: [['producto_id' => $extra->id, 'cantidad' => 2, 'costo_unitario' => 10]]);

        $this->assertEquals(2, (float) $extra->fresh()->stock);
        $this->assertEquals(4, (float) $orden->detalles()->first()->cantidad_recibida);
    }

    public function test_anular_la_compra_devuelve_lo_recibido_a_pendiente(): void
    {
        $orden = $this->ordenEnviada();
        $this->recibir($orden, 3, 'B0100000001');
        $segunda = $this->recibir($orden, 7, 'B0100000002');
        $this->assertEquals(EstadoOrdenCompra::COMPLETADA, $orden->fresh()->estado);

        app(CompraService::class)->anular($segunda, 'Factura equivocada', $this->admin->id);

        $orden->refresh();
        $this->assertEquals(EstadoOrdenCompra::RECEPCION_PARCIAL, $orden->estado);
        $this->assertEquals(3, (float) $orden->detalles()->first()->cantidad_recibida);
        $this->assertNotNull(RecepcionCompra::where('compra_id', $segunda->id)->sole()->anulada_en);
        $this->assertEquals(3, (float) $this->producto->fresh()->stock);
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
        $orden = $this->ordenEnviada();
        $this->recibir($orden, 3);

        $orden->refresh();
        $this->expectException(RuntimeException::class);
        $this->service->cancelar($orden);
    }

    public function test_porcentaje_recibido_calcula_correctamente(): void
    {
        $orden = $this->ordenEnviada();

        $this->assertEquals(0.0, $orden->porcentajeRecibido());

        $this->recibir($orden, 5);

        $orden->load('detalles');
        $this->assertEquals(50.0, $orden->porcentajeRecibido());
    }

    public function test_kardex_registra_entrada_por_la_compra(): void
    {
        $orden = $this->ordenEnviada();

        $compra = $this->recibir($orden, 5);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $this->producto->id,
            'tipo' => 'entrada',
            'origen' => OrigenMovimiento::COMPRA->value,
            'referencia_id' => $compra->id,
            'cantidad' => 5,
        ]);
    }

    public function test_aislamiento_empresa_id(): void
    {
        $orden = $this->ordenEnviada();

        $otraEmpresa = Empresa::factory()->create();
        app(RolesEmpresaService::class)->sembrarRolesBase($otraEmpresa);
        $proveedorOtra = Proveedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Proveedor otra', 'tipo' => TipoProveedor::FORMAL,
            'rnc' => '987654321', 'activo' => true,
        ]);

        $this->assertEquals($this->empresa->id, $orden->empresa_id);

        // Otra empresa no puede recibir esta orden, aunque mande su id.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no existe');
        $this->service->ordenParaRecibir($orden->id, $proveedorOtra, $otraEmpresa);
    }

    public function test_no_recibir_en_borrador(): void
    {
        $orden = $this->service->crear($this->datosOrden(), $this->admin->id, $this->empresa);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no admite recepciones');

        $this->recibir($orden, 1);
    }

    public function test_no_recibir_con_otro_proveedor(): void
    {
        $orden = $this->ordenEnviada();
        $otro = Proveedor::create([
            'empresa_id' => $this->empresa->id, 'nombre' => 'Otro proveedor', 'tipo' => TipoProveedor::FORMAL,
            'rnc' => '111222333', 'activo' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('otro proveedor');

        $this->service->ordenParaRecibir($orden->id, $otro, $this->empresa);
    }

    public function test_crear_sin_lineas_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('al menos una línea');

        $this->service->crear($this->datosOrden(['lineas' => []]), $this->admin->id, $this->empresa);
    }
}
