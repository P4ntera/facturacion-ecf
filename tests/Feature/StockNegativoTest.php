<?php

namespace Tests\Feature;

use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Exceptions\StockInsuficienteException;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Widgets\AlertasWidget;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\CompraService;
use App\Services\InventarioService;
use App\Services\RolesEmpresaService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Entrega 2 — vender sin stock: interruptor por empresa, sin tope de unidades, solo al vender,
 * con marca en el Kardex y alerta hasta que se corrija.
 */
class StockNegativoTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'prefijo' => 'B02',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'NEG-1', 'nombre' => 'Refresco', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 30, 'precio' => 50, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 2, 'stock_minimo' => 0, 'activo' => true,
        ]);
    }

    private function permitir(bool $permitir = true): void
    {
        $this->empresaDefault->config()->update(['permite_stock_negativo' => $permitir]);
    }

    private function vender(float $cantidad): void
    {
        app(VentaService::class)->registrar([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad]],
        ], $this->empresaDefault);
    }

    private function stock(): float
    {
        return (float) $this->producto->fresh()->stock;
    }

    public function test_por_defecto_no_se_puede_vender_sin_stock(): void
    {
        $this->assertFalse($this->empresaDefault->config()->permite_stock_negativo);

        $this->expectException(StockInsuficienteException::class);

        $this->vender(5);
    }

    public function test_con_el_interruptor_la_venta_deja_el_stock_en_negativo_y_marca_el_kardex(): void
    {
        $this->permitir();

        $this->vender(5);

        $this->assertEquals(-3, $this->stock());

        $movimiento = MovimientoInventario::where('producto_id', $this->producto->id)->sole();
        $this->assertTrue($movimiento->dejo_stock_negativo);
        $this->assertSame(OrigenMovimiento::VENTA, $movimiento->origen);
    }

    public function test_sin_tope_de_unidades(): void
    {
        $this->permitir();

        $this->vender(500);

        $this->assertEquals(-498, $this->stock());
    }

    public function test_una_venta_que_no_baja_de_cero_no_queda_marcada(): void
    {
        $this->permitir();

        $this->vender(2);

        $this->assertFalse(MovimientoInventario::where('producto_id', $this->producto->id)->sole()->dejo_stock_negativo);
    }

    public function test_un_ajuste_manual_sigue_sin_poder_dejar_el_stock_en_negativo(): void
    {
        $this->permitir();

        $this->expectException(StockInsuficienteException::class);

        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::AJUSTE, OrigenMovimiento::AJUSTE, -5,
        ));
    }

    public function test_una_compra_sube_el_stock_aunque_siga_negativo(): void
    {
        $this->permitir();
        $this->vender(7); // queda en -5

        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::COMPRA, 2,
        ));

        $this->assertEquals(-3, $this->stock());
    }

    public function test_anular_una_compra_ya_vendida_sigue_bloqueado(): void
    {
        $this->permitir();
        $user = User::factory()->create();
        $proveedor = Proveedor::create(['nombre' => 'Proveedor', 'tipo' => TipoProveedor::FORMAL, 'rnc' => '130000077', 'activo' => true]);

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
            'ncf' => 'B0100000001',
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 10, 'costo_unitario' => 30]],
        ], $user->id, $this->empresaDefault);
        $this->vender(12); // de 12 a 0

        // Anular la compra sacaría 10 que ya se vendieron: eso se corrige con un ajuste, no aquí.
        $this->expectException(StockInsuficienteException::class);

        app(CompraService::class)->anular($compra, 'Error', $user->id);
    }

    public function test_el_pos_deja_agregar_un_producto_sin_stock_solo_si_esta_permitido(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $cajero = User::factory()->create();
        app(RolesEmpresaService::class)->asignarAdministrador($cajero, $this->empresaDefault);
        $this->producto->update(['stock' => 0]);

        // Apagado: la línea se marca sin stock y no se puede cobrar.
        $bloqueado = Livewire::actingAs($cajero)
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $this->producto->id);
        $this->assertTrue($bloqueado->instance()->hayLineasConStockInsuficiente());

        $this->permitir();

        // Encendido: la misma línea no bloquea el cobro.
        $pos = Livewire::actingAs($cajero)
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $this->producto->id)
            ->assertSet('carrito.0.producto_id', $this->producto->id)
            ->set('carrito.0.cantidad', 3);

        $this->assertFalse($pos->instance()->hayLineasConStockInsuficiente());
    }

    public function test_la_alerta_del_dashboard_sigue_hasta_que_se_corrige(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        app(RolesEmpresaService::class)->asignarAdministrador($admin, $this->empresaDefault);
        $this->permitir();
        $this->vender(5); // -3

        Livewire::actingAs($admin)->test(AlertasWidget::class)
            ->assertSee('Productos en negativo')
            ->assertSee('Refresco (-3)');

        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::COMPRA, 3,
        ));

        Livewire::actingAs($admin)->test(AlertasWidget::class)
            ->assertDontSee('Productos en negativo');
    }
}
