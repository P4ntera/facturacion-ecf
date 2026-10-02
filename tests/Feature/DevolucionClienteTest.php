<?php

namespace Tests\Feature;

use App\Enums\DestinoDevolucion;
use App\Enums\EstadoFiscal;
use App\Enums\FormaPago;
use App\Enums\FormaReembolso;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\VentaInvalidaException;
use App\Filament\Resources\VentaResource\Pages\ListVentas;
use App\Models\ArqueoCaja;
use App\Models\Cliente;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\ArqueoCajaService;
use App\Services\ReporteService;
use App\Services\RolesEmpresaService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Entrega 3 — devoluciones de clientes: parámetros por empresa, devolución en la caja de hoy,
 * parcial, con destino por producto (inventario o merma), y su documento: E34, B04 o interno.
 */
class DevolucionClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $cajero;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->cajero = User::factory()->create();
        app(RolesEmpresaService::class)->asignarAdministrador($this->cajero, $this->empresaDefault);
        $this->actingAs($this->cajero);

        foreach (['B02' => TipoComprobante::FACTURA_CONSUMO_FISICA, 'B04' => TipoComprobante::NOTA_CREDITO_FISICA] as $prefijo => $tipo) {
            SecuenciaNcf::create([
                'tipo_comprobante' => $tipo->value, 'prefijo' => $prefijo, 'secuencia_desde' => 1,
                'secuencia_actual' => 1, 'secuencia_hasta' => 1000, 'vencimiento' => now()->addYear(), 'activa' => true,
            ]);
        }

        // Precio 100 + ITBIS 18 = 118 por unidad.
        $this->producto = Producto::create([
            'codigo' => 'DEV-1', 'nombre' => 'Licuadora', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 60, 'precio' => 100, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 10, 'stock_minimo' => 0, 'activo' => true,
        ]);
    }

    private function caja(): ArqueoCaja
    {
        return app(ArqueoCajaService::class)->abrir('500.00', $this->cajero->id, $this->empresaDefault);
    }

    private function vender(float $cantidad = 2, ?TipoComprobante $tipo = TipoComprobante::FACTURA_CONSUMO_FISICA, array $extra = []): Venta
    {
        return app(VentaService::class)->registrar([
            'tipo_comprobante' => $tipo?->value,
            'sin_comprobante' => $tipo === null,
            'forma_pago' => FormaPago::EFECTIVO->value,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad]],
        ] + $extra, $this->empresaDefault)->refresh();
    }

    private function devolver(Venta $venta, float $cantidad = 1, DestinoDevolucion $destino = DestinoDevolucion::INVENTARIO, ?FormaReembolso $reembolso = FormaReembolso::EFECTIVO, ?ArqueoCaja $arqueo = null): Venta
    {
        return app(VentaService::class)->registrarDevolucion(
            $venta,
            $this->empresaDefault,
            [['detalle_venta_id' => $venta->detalles()->first()->id, 'cantidad' => $cantidad, 'destino' => $destino]],
            'El cliente cambió de opinión',
            $reembolso,
            $arqueo,
        );
    }

    private function stock(): float
    {
        return (float) $this->producto->fresh()->stock;
    }

    // ── Documento ─────────────────────────────────────────────────────────

    public function test_venta_fisica_emite_b04_y_el_producto_vuelve_al_inventario(): void
    {
        $arqueo = $this->caja();
        $venta = $this->vender(2);
        $this->assertEquals(8, $this->stock());

        $nc = $this->devolver($venta, 1, arqueo: $arqueo);

        $this->assertSame(TipoComprobante::NOTA_CREDITO_FISICA, $nc->tipo_comprobante);
        $this->assertSame('B0400000001', $nc->ncf);
        $this->assertSame($venta->ncf, $nc->ncf_modifica);
        $this->assertSame(EstadoFiscal::NO_APLICA, $nc->estado_fiscal);
        $this->assertEquals(118, (float) $nc->total);
        $this->assertEquals(9, $this->stock());
    }

    public function test_venta_sin_comprobante_genera_devolucion_interna_sin_ncf(): void
    {
        $this->empresaDefault->config()->update(['permite_ventas_sin_comprobante' => true]);
        $arqueo = $this->caja();
        $venta = $this->vender(2, null);

        $nc = $this->devolver($venta, 1, arqueo: $arqueo);

        $this->assertNull($nc->tipo_comprobante);
        $this->assertNull($nc->ncf);
        $this->assertSame($venta->id, $nc->venta_modificada_id);
        $this->assertEquals(9, $this->stock());
    }

    public function test_la_b04_sale_en_el_607_con_el_ncf_que_modifica(): void
    {
        $venta = $this->vender(2);
        $this->devolver($venta, 1, reembolso: FormaReembolso::MISMO_MEDIO);

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth(), $this->empresaDefault->id);

        $fila = $filas->firstWhere('numero_comprobante', 'B0400000001');
        $this->assertNotNull($fila);
        $this->assertSame($venta->ncf, $fila['numero_comprobante_modificado']);
    }

    // ── Parcial y destino ─────────────────────────────────────────────────

    public function test_es_parcial_y_no_se_puede_devolver_mas_de_lo_vendido(): void
    {
        $arqueo = $this->caja();
        $venta = $this->vender(3);

        $this->devolver($venta, 2, arqueo: $arqueo);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('Disponible: 1');

        $this->devolver($venta, 2, arqueo: $arqueo);
    }

    public function test_merma_entra_y_sale_del_kardex_sin_volver_al_inventario(): void
    {
        $arqueo = $this->caja();
        $venta = $this->vender(2);

        $nc = $this->devolver($venta, 1, DestinoDevolucion::MERMA, arqueo: $arqueo);

        $this->assertEquals(8, $this->stock()); // no vuelve a la venta
        $this->assertSame(DestinoDevolucion::MERMA, $nc->detalles()->first()->destino_devolucion);
        $this->assertTrue(MovimientoInventario::where('referencia_id', $nc->id)->where('origen', OrigenMovimiento::MERMA)->exists());
        $this->assertTrue(MovimientoInventario::where('referencia_id', $nc->id)->where('origen', OrigenMovimiento::DEVOLUCION_VENTA)->exists());
    }

    // ── Dinero y caja ─────────────────────────────────────────────────────

    public function test_el_efectivo_devuelto_se_resta_en_el_cierre_de_la_caja_de_hoy(): void
    {
        $arqueo = $this->caja();
        $venta = $this->vender(2, extra: ['arqueo_caja_id' => $arqueo->id]); // 236 en efectivo

        $nc = $this->devolver($venta, 1, arqueo: $arqueo); // devuelve 118
        $this->assertSame($arqueo->id, $nc->arqueo_caja_id);

        $cerrado = app(ArqueoCajaService::class)->cerrar($arqueo, '618.00', null, $this->cajero->id);

        $this->assertEquals(236, (float) $cerrado->total_ventas_efectivo);
        $this->assertEquals(118, (float) $cerrado->total_devoluciones_efectivo);
        $this->assertEquals(618, (float) $cerrado->efectivo_esperado); // 500 + 236 − 118
        $this->assertEquals(0, (float) $cerrado->diferencia);
    }

    public function test_devolver_efectivo_exige_la_caja_abierta(): void
    {
        $venta = $this->vender(2);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('caja abierta');

        $this->devolver($venta, 1, arqueo: null);
    }

    public function test_reembolso_por_el_mismo_medio_no_toca_la_caja(): void
    {
        $arqueo = $this->caja();
        $venta = $this->vender(2, extra: ['arqueo_caja_id' => $arqueo->id]);

        $nc = $this->devolver($venta, 1, reembolso: FormaReembolso::MISMO_MEDIO);

        $this->assertNull($nc->arqueo_caja_id);
        $cerrado = app(ArqueoCajaService::class)->cerrar($arqueo, '736.00', null, $this->cajero->id);
        $this->assertEquals(736, (float) $cerrado->efectivo_esperado);
    }

    public function test_venta_a_credito_primero_rebaja_la_cuenta_por_cobrar(): void
    {
        $cliente = Cliente::create([
            'nombre' => 'Cliente crédito', 'tipo_documento' => TipoDocumentoCliente::CEDULA,
            'documento' => '00112345678', 'activo' => true,
        ]);
        $venta = $this->vender(2, extra: ['cliente_id' => $cliente->id, 'tipo_pago' => TipoPago::CREDITO->value]);
        $this->assertEquals(236, (float) $venta->cuentaPorCobrar->monto_total);

        // Sin forma de reembolso: la deuda cubre toda la devolución.
        $nc = $this->devolver($venta, 1, reembolso: null);

        $this->assertEquals(118, (float) $venta->cuentaPorCobrar->fresh()->monto_total);
        $this->assertEquals(118, (float) $nc->monto_rebaja_cxc);
        $this->assertNull($nc->monto_reembolso);
    }

    // ── Reglas de la empresa ─────────────────────────────────────────────

    public function test_si_la_empresa_no_acepta_devoluciones_se_rechaza(): void
    {
        $this->empresaDefault->config()->update(['acepta_devoluciones' => false]);
        $venta = $this->vender(2);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('no acepta devoluciones');

        $this->devolver($venta, 1, reembolso: FormaReembolso::MISMO_MEDIO);
    }

    public function test_pasado_el_plazo_se_rechaza(): void
    {
        $this->empresaDefault->config()->update(['devolucion_plazo_dias' => 30]);
        $venta = $this->vender(2);
        $venta->update(['fecha' => now()->subDays(31)]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('plazo');

        $this->devolver($venta->fresh(), 1, reembolso: FormaReembolso::MISMO_MEDIO);
    }

    public function test_dentro_del_plazo_se_acepta(): void
    {
        $this->empresaDefault->config()->update(['devolucion_plazo_dias' => 30]);
        $venta = $this->vender(2);
        $venta->update(['fecha' => now()->subDays(30)]);

        $nc = $this->devolver($venta->fresh(), 1, reembolso: FormaReembolso::MISMO_MEDIO);

        $this->assertNotNull($nc->id);
    }

    public function test_una_forma_de_reembolso_no_permitida_se_rechaza(): void
    {
        $this->empresaDefault->config()->update(['devolucion_reembolsos' => [FormaReembolso::MISMO_MEDIO->value]]);
        $arqueo = $this->caja();
        $venta = $this->vender(2);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('no acepta reembolsos');

        $this->devolver($venta, 1, reembolso: FormaReembolso::EFECTIVO, arqueo: $arqueo);
    }

    public function test_por_encima_del_monto_hace_falta_un_supervisor(): void
    {
        $this->empresaDefault->config()->update(['devolucion_monto_supervisor' => 100]);
        $venta = $this->vender(2);

        // Un cajero sin el permiso de supervisor.
        $cajeroSimple = User::factory()->create();
        $cajeroSimple->givePermissionTo('ventas.devolucion');

        try {
            app(VentaService::class)->registrarDevolucion(
                $venta, $this->empresaDefault,
                [['detalle_venta_id' => $venta->detalles()->first()->id, 'cantidad' => 1]],
                'Motivo', FormaReembolso::MISMO_MEDIO, null, $cajeroSimple,
            );
            $this->fail('Debió pedir un supervisor.');
        } catch (VentaInvalidaException $e) {
            $this->assertStringContainsString('supervisor', $e->getMessage());
        }

        // El administrador (tiene ventas.devolucion_autorizar) sí puede.
        $nc = $this->devolver($venta, 1, reembolso: FormaReembolso::MISMO_MEDIO);
        $this->assertNotNull($nc->id);
    }

    // ── Pantalla ─────────────────────────────────────────────────────────

    public function test_el_boton_aparece_para_ventas_fisicas_y_se_oculta_si_la_empresa_no_acepta(): void
    {
        $venta = $this->vender(2);

        Livewire::test(ListVentas::class)->assertTableActionVisible('devolucionParcial', $venta);

        $this->empresaDefault->config()->update(['acepta_devoluciones' => false]);

        Livewire::test(ListVentas::class)->assertTableActionHidden('devolucionParcial', $venta);
    }
}
