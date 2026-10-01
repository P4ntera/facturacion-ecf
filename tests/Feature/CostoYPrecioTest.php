<?php

namespace Tests\Feature;

use App\Enums\MetodoCosto;
use App\Enums\RedondeoPrecio;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Filament\Pages\ManageFacturacion;
use App\Filament\Resources\CompraResource\Pages\ViewCompra;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\CompraService;
use App\Services\CostoPrecioService;
use App\Services\OrdenCompraService;
use App\Services\ReporteService;
use App\Services\RolesEmpresaService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Entrega 1 de costo y precio: método de costo por empresa (manual / última compra / promedio
 * ponderado), costo guardado en cada línea de venta, % de ganancia por categoría o producto y
 * precio sugerido con redondeo, que solo se aplica cuando el usuario lo aprueba.
 */
class CostoYPrecioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Proveedor $proveedor;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->proveedor = Proveedor::create([
            'nombre' => 'Distribuidora Test',
            'tipo' => TipoProveedor::FORMAL,
            'rnc' => '130000099',
            'activo' => true,
        ]);
        $this->producto = Producto::create([
            'codigo' => 'ARROZ',
            'nombre' => 'Saco de arroz',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 80,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    private function usarMetodo(MetodoCosto $metodo): void
    {
        $this->empresaDefault->config()->update(['metodo_costo' => $metodo]);
    }

    private function comprar(float $cantidad, float $costo, string $ncf = 'B0100000001'): Compra
    {
        return app(CompraService::class)->crear([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
            'ncf' => $ncf,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad, 'costo_unitario' => $costo]],
        ], $this->user->id, $this->empresaDefault);
    }

    private function costo(): string
    {
        return (string) $this->producto->fresh()->costo;
    }

    // ── Método de costo ────────────────────────────────────────────────────

    public function test_por_defecto_el_costo_es_el_de_la_ultima_compra(): void
    {
        $this->assertSame(MetodoCosto::ULTIMA_COMPRA, $this->empresaDefault->config()->metodo_costo);

        $this->comprar(10, 70);

        $this->assertSame('70.00', $this->costo());
    }

    public function test_metodo_manual_las_compras_no_cambian_el_costo(): void
    {
        $this->usarMetodo(MetodoCosto::MANUAL);

        $this->comprar(10, 70);

        $this->assertSame('50.00', $this->costo());
        $this->assertEquals(110, (float) $this->producto->fresh()->stock);
    }

    /** El ejemplo del saco de arroz: 100 a RD$50 + 10 a RD$70 = RD$5,700 / 110 = RD$51.82. */
    public function test_promedio_ponderado_mezcla_lo_que_habia_con_lo_que_entra(): void
    {
        $this->usarMetodo(MetodoCosto::PROMEDIO_PONDERADO);

        $this->comprar(10, 70);

        $this->assertSame('51.82', $this->costo());
    }

    public function test_promedio_ponderado_con_el_almacen_vacio_toma_el_costo_de_la_compra(): void
    {
        $this->usarMetodo(MetodoCosto::PROMEDIO_PONDERADO);
        $this->producto->update(['stock' => 0]);

        $this->comprar(10, 70);

        $this->assertSame('70.00', $this->costo());
    }

    public function test_anular_una_compra_saca_su_efecto_del_promedio(): void
    {
        $this->usarMetodo(MetodoCosto::PROMEDIO_PONDERADO);
        $compra = $this->comprar(10, 70);
        $this->assertSame('51.82', $this->costo());

        app(CompraService::class)->anular($compra, 'Factura equivocada', $this->user->id);

        // 110 × 51.82 − 10 × 70 = 5,000.20 → ÷ 100 = 50.00
        $this->assertSame('50.00', $this->costo());
        $this->assertEquals(100, (float) $this->producto->fresh()->stock);
    }

    public function test_la_recepcion_de_una_orden_de_compra_tambien_actualiza_el_costo(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(RolesEmpresaService::class)->asignarAdministrador($this->user, $this->empresaDefault);
        $this->actingAs($this->user);
        $this->usarMetodo(MetodoCosto::PROMEDIO_PONDERADO);

        $servicio = app(OrdenCompraService::class);
        $orden = $servicio->crear([
            'proveedor_id' => $this->proveedor->id,
            'fecha' => now()->toDateString(),
            'fecha_esperada' => now()->addDays(7)->toDateString(),
            'notas' => null,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad_solicitada' => 10, 'precio_unitario' => 70]],
        ], $this->user->id, $this->empresaDefault);
        $servicio->aprobar($orden);

        $servicio->registrarRecepcion($orden, [
            ['detalle_orden_compra_id' => $orden->detalles->first()->id, 'cantidad_recibida' => 10],
        ], $this->empresaDefault);

        $this->assertSame('51.82', $this->costo());
    }

    // ── Costo guardado en la venta ─────────────────────────────────────────

    public function test_la_venta_guarda_el_costo_del_momento_por_unidad_vendida(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'prefijo' => 'B02',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
        $caja = ProductoPresentacion::create([
            'empresa_id' => $this->empresaDefault->id,
            'producto_id' => $this->producto->id,
            'nombre' => 'Fardo de 12',
            'factor' => 12,
            'codigo_barra' => 'FARDO12',
            'precio' => 900,
            'es_base' => false,
            'activa' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'lineas' => [
                ['producto_id' => $this->producto->id, 'cantidad' => 2],
                ['producto_id' => $this->producto->id, 'presentacion_id' => $caja->id, 'cantidad' => 1],
            ],
        ], $this->empresaDefault);

        $lineas = $venta->detalles()->orderBy('id')->get();
        $this->assertSame('50.00', (string) $lineas[0]->costo_unitario);
        $this->assertSame('600.00', (string) $lineas[1]->costo_unitario); // 12 × 50

        // Que luego cambie el costo del producto no altera lo ya vendido.
        $this->comprar(10, 70);
        $this->assertSame('50.00', (string) $lineas[0]->fresh()->costo_unitario);

        // Y el reporte de margen usa el costo guardado: 2 × 80 + 900 de ingresos, 2 × 50 + 600 de costo.
        $fila = app(ReporteService::class)
            ->margenPorProductoQuery(now()->subDay(), now()->addDay(), $this->empresaDefault->id)
            ->first();
        $this->assertEquals(700, (float) $fila->costo_total);
    }

    // ── Precio sugerido ────────────────────────────────────────────────────

    public function test_precio_sugerido_usa_el_porcentaje_de_la_categoria_o_el_del_producto(): void
    {
        $servicio = app(CostoPrecioService::class);
        $categoria = Categoria::create(['nombre' => 'Granos', 'activo' => true, 'margen_ganancia' => 30]);
        $this->producto->update(['categoria_id' => $categoria->id]);

        $this->assertSame('65.00', $servicio->precioSugerido($this->producto->fresh(), $this->empresaDefault));

        $this->producto->update(['margen_ganancia' => 40]);
        $this->assertSame('70.00', $servicio->precioSugerido($this->producto->fresh(), $this->empresaDefault));

        $sinMargen = Producto::create([
            'codigo' => 'SIN', 'nombre' => 'Sin margen', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50, 'precio' => 80, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);
        $this->assertNull($servicio->precioSugerido($sinMargen, $this->empresaDefault));
    }

    public function test_precio_sugerido_suma_el_itbis_si_los_precios_lo_incluyen(): void
    {
        $this->empresaDefault->config()->update(['precio_incluye_itbis' => true]);
        $this->producto->update(['margen_ganancia' => 30]);

        // 50 × 1.30 = 65.00 → × 1.18 = 76.70
        $this->assertSame('76.70', app(CostoPrecioService::class)->precioSugerido($this->producto->fresh(), $this->empresaDefault));
    }

    public function test_el_redondeo_es_siempre_hacia_arriba_al_multiplo_elegido(): void
    {
        $servicio = app(CostoPrecioService::class);
        $this->producto->update(['costo' => 48.77, 'margen_ganancia' => 30]); // 63.401

        $esperados = [
            RedondeoPrecio::NINGUNO->value => '63.40',
            RedondeoPrecio::UNIDAD->value => '64.00',
            RedondeoPrecio::CINCO->value => '65.00',
            RedondeoPrecio::DIEZ->value => '70.00',
        ];

        foreach ($esperados as $redondeo => $esperado) {
            $this->empresaDefault->config()->update(['redondeo_precio' => $redondeo]);
            $this->assertSame($esperado, $servicio->precioSugerido($this->producto->fresh(), $this->empresaDefault), "redondeo {$redondeo}");
        }

        // Un precio que ya es múltiplo no sube: 50 × 1.30 = 65.00 con "a 5" se queda en 65.
        $this->producto->update(['costo' => 50]);
        $this->empresaDefault->config()->update(['redondeo_precio' => RedondeoPrecio::CINCO]);
        $this->assertSame('65.00', $servicio->precioSugerido($this->producto->fresh(), $this->empresaDefault));
    }

    public function test_la_compra_no_cambia_el_precio_solo_lo_sugiere(): void
    {
        $this->producto->update(['margen_ganancia' => 30]);

        $compra = $this->comprar(10, 70); // costo → 70, sugerido 91

        $this->assertSame('80.00', (string) $this->producto->fresh()->precio);

        $sugerencias = app(CostoPrecioService::class)->sugerenciasParaCompra($compra);
        $this->assertCount(1, $sugerencias);
        $this->assertSame('80.00', $sugerencias[0]['precio_actual']);
        $this->assertSame('91.00', $sugerencias[0]['precio_sugerido']);
    }

    public function test_revisar_precios_aplica_solo_los_marcados_y_respeta_el_ajuste_manual(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(RolesEmpresaService::class)->asignarAdministrador($this->user, $this->empresaDefault);

        $otro = Producto::create([
            'codigo' => 'HABICHUELA', 'nombre' => 'Habichuela', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 40, 'precio' => 60, 'tasa_itbis' => TasaItbis::DIECIOCHO, 'margen_ganancia' => 50,
            'controla_stock' => true, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);
        $this->producto->update(['margen_ganancia' => 30]);

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
            'ncf' => 'B0100000005',
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $this->producto->id, 'cantidad' => 10, 'costo_unitario' => 70], // sugerido 91
                ['producto_id' => $otro->id, 'cantidad' => 10, 'costo_unitario' => 44],          // sugerido 66
            ],
        ], $this->user->id, $this->empresaDefault);

        $componente = Livewire::actingAs($this->user)
            ->test(ViewCompra::class, ['record' => $compra->id])
            ->assertActionVisible('revisarPrecios')
            ->mountAction('revisarPrecios');

        // El modal abre con las dos sugerencias marcadas. Filament le da a cada fila una clave
        // propia: se editan las filas reales, como lo haría el usuario.
        $filas = $componente->instance()->mountedActions[0]['data']['precios'];
        $this->assertCount(2, $filas);
        $sugeridos = collect($filas)->mapWithKeys(fn (array $f) => [$f['producto_id'] => $f['precio_nuevo']])->all();
        $this->assertEquals([66, 91], [(float) $sugeridos[$otro->id], (float) $sugeridos[$this->producto->id]]);

        foreach ($filas as $clave => $fila) {
            $fila['producto_id'] === $otro->id
                ? $componente->set("mountedActions.0.data.precios.{$clave}.precio_nuevo", '65.00')
                : $componente->set("mountedActions.0.data.precios.{$clave}.aplicar", false);
        }

        $componente->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame('65.00', (string) $otro->fresh()->precio);          // ajustado a mano
        $this->assertSame('80.00', (string) $this->producto->fresh()->precio); // no marcado
    }

    public function test_la_configuracion_guarda_el_metodo_de_costo_y_el_redondeo(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(RolesEmpresaService::class)->asignarAdministrador($this->user, $this->empresaDefault);

        Livewire::actingAs($this->user)
            ->test(ManageFacturacion::class)
            ->set('data.metodo_costo', MetodoCosto::PROMEDIO_PONDERADO->value)
            ->set('data.redondeo_precio', RedondeoPrecio::CINCO->value)
            ->call('save')
            ->assertHasNoErrors();

        $config = $this->empresaDefault->fresh()->config();
        $this->assertSame(MetodoCosto::PROMEDIO_PONDERADO, $config->metodo_costo);
        $this->assertSame(RedondeoPrecio::CINCO, $config->redondeo_precio);
    }
}
