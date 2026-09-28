<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\EcfInvalidoException;
use App\Exceptions\VentaInvalidaException;
use App\Jobs\EnviarEcfJob;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\CompraService;
use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\EcfBuilder;
use App\Services\Dgii\EnvioEcfService;
use App\Services\ReporteService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\GatewayStub;
use Tests\TestCase;

/**
 * "Programa para que no se equivoquen": lo que no debe llegar a la DGII ni por accidente
 * (montos negativos, descuentos imposibles, totales que no cuadran, reenvíos de un e-NCF que la
 * DGII ya tiene) se bloquea en el backend, aunque el POS/formulario ya lo impida.
 */
class ValidacionesFiscalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Solo el envío e-CF: se prueba el builder/job por separado.
        Queue::fake([EnviarEcfJob::class]);

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value, 'prefijo' => 'E32',
            'secuencia_desde' => 1, 'secuencia_actual' => 1, 'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(), 'activa' => true,
        ]);
    }

    private function producto(string $codigo = 'VAL-1', float $precio = 100, TasaItbis $tasa = TasaItbis::DIECIOCHO, array $extra = []): Producto
    {
        return Producto::create([
            'codigo' => $codigo, 'nombre' => "Producto {$codigo}", 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 10, 'precio' => $precio, 'tasa_itbis' => $tasa,
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
            ...$extra,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $lineas */
    private function vender(array $lineas, array $datos = []): Venta
    {
        return app(VentaService::class)->registrar($datos + ['lineas' => $lineas], $this->empresaDefault);
    }

    private function esperarRechazo(string $mensaje, callable $accion): void
    {
        try {
            $accion();
            $this->fail("Se esperaba un rechazo: {$mensaje}");
        } catch (VentaInvalidaException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }

        $this->assertSame(0, Venta::count(), 'No debe quedar ninguna venta (ni NCF consumido).');
        $this->assertEquals(1, SecuenciaNcf::sole()->secuencia_actual);
    }

    // ------------------------------------------------------------------ líneas

    public function test_no_vende_sin_productos(): void
    {
        $this->esperarRechazo('al menos una línea', fn () => $this->vender([]));
    }

    public function test_no_vende_con_cantidad_cero_o_negativa(): void
    {
        $producto = $this->producto();

        $this->esperarRechazo('mayor que cero', fn () => $this->vender([['producto_id' => $producto->id, 'cantidad' => 0]]));
        $this->esperarRechazo('mayor que cero', fn () => $this->vender([['producto_id' => $producto->id, 'cantidad' => -3]]));
    }

    public function test_no_vende_producto_desactivado_ni_de_otra_empresa(): void
    {
        $inactivo = $this->producto('VAL-INACTIVO', extra: ['activo' => false]);
        $ajeno = $this->producto('VAL-AJENO', extra: ['empresa_id' => Empresa::factory()->create()->id]);

        $this->esperarRechazo('no existe o está inactivo', fn () => $this->vender([['producto_id' => $inactivo->id, 'cantidad' => 1]]));
        $this->esperarRechazo('no existe o está inactivo', fn () => $this->vender([['producto_id' => $ajeno->id, 'cantidad' => 1]]));
    }

    public function test_precio_negativo_se_rechaza_incluso_con_permite_precio_cero(): void
    {
        $producto = $this->producto();

        $this->esperarRechazo('no puede ser negativo', fn () => $this->vender(
            [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => -50]],
            ['permite_precio_cero' => true],
        ));
    }

    public function test_descuento_de_linea_negativo_o_mayor_que_la_linea_se_rechaza(): void
    {
        $producto = $this->producto();

        $this->esperarRechazo('no puede ser negativo', fn () => $this->vender([['producto_id' => $producto->id, 'cantidad' => 1, 'descuento' => -10]]));
        $this->esperarRechazo('no puede ser mayor que el importe', fn () => $this->vender([['producto_id' => $producto->id, 'cantidad' => 1, 'descuento' => 150]]));
    }

    // ------------------------------------------------------------------ descuento global

    public function test_descuento_global_negativo_o_mayor_que_el_subtotal_se_rechaza(): void
    {
        $producto = $this->producto();

        $this->esperarRechazo('no puede ser negativo', fn () => $this->vender([['producto_id' => $producto->id, 'cantidad' => 1]], ['descuento_global' => '-10']));
        $this->esperarRechazo('no puede ser mayor que el subtotal', fn () => $this->vender([['producto_id' => $producto->id, 'cantidad' => 1]], ['descuento_global' => '100.01']));
    }

    /** El descuento global se prorratea ANTES del ITBIS, y el e-CF cuadra. */
    public function test_descuento_global_se_prorratea_y_el_ecf_cuadra(): void
    {
        $a = $this->producto('VAL-A', 100);
        $b = $this->producto('VAL-B', 33.33);
        $exento = $this->producto('VAL-EX', 50, TasaItbis::CERO);

        $venta = $this->vender([
            ['producto_id' => $a->id, 'cantidad' => 2],
            ['producto_id' => $b->id, 'cantidad' => 3],
            ['producto_id' => $exento->id, 'cantidad' => 1],
        ], ['descuento_global' => '25.00']);

        // Bruto: 200 + 99.99 + 50 = 349.99.
        $this->assertSame('349.99', $venta->subtotal);
        $this->assertSame(
            $venta->descuento,
            $venta->detalles->reduce(fn ($suma, $d) => bcadd($suma, $d->descuento, 2), '0.00'),
            'La suma de los descuentos de línea es el descuento de la venta.',
        );
        $this->assertEqualsWithDelta(25.00, (float) $venta->descuento, 0.02);

        // ITBIS solo sobre las bases netas gravadas.
        $this->assertSame(bcmul($venta->monto_gravado_18, '0.18', 2), $venta->itbis_18);
        $this->assertSame($venta->total, bcadd($venta->subtotalNeto(), $venta->total_itbis, 2));

        $ecf = app(EcfBuilder::class)->construir($venta->fresh());
        $totales = $ecf['ECF']['Encabezado']['Totales'];
        $this->assertSame(
            $totales['MontoTotal'],
            bcadd(bcadd($totales['MontoGravadoI1'], $totales['MontoGravadoI3'], 2), $totales['TotalITBIS'], 2),
        );
        $this->assertSame(
            bcadd($totales['MontoGravadoI1'], $totales['MontoGravadoI3'], 2),
            collect($ecf['ECF']['DetallesItems']['Item'])->reduce(fn ($suma, $item) => bcadd($suma, $item['MontoItem'], 2), '0.00'),
        );
    }

    public function test_descuento_global_con_precios_que_incluyen_itbis(): void
    {
        $this->empresaDefault->config()->update(['precio_incluye_itbis' => true]);
        $producto = $this->producto('VAL-INC', 118);

        // Precio con ITBIS 118 -> base 100. 10% sobre la base = 10.
        $venta = $this->vender([['producto_id' => $producto->id, 'cantidad' => 1]], ['descuento_global' => '10.00']);

        $this->assertSame('100.00', $venta->subtotal);
        $this->assertSame('10.00', $venta->descuento);
        $this->assertSame('90.00', $venta->monto_gravado_18);
        $this->assertSame('16.20', $venta->total_itbis);
        $this->assertSame('106.20', $venta->total);
        app(EcfBuilder::class)->construir($venta->fresh());
    }

    public function test_el_607_reporta_la_base_neta_del_descuento(): void
    {
        $producto = $this->producto();
        $this->vender([['producto_id' => $producto->id, 'cantidad' => 1]], ['descuento_global' => '10.00']);

        $fila = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth())->sole();

        $this->assertSame('90.00', $fila['monto_facturado']);
        $this->assertSame('16.20', $fila['itbis_facturado']);
    }

    // ------------------------------------------------------------------ e-CF

    public function test_el_builder_rechaza_un_ecf_que_no_cuadra_o_con_ncf_malformado(): void
    {
        $venta = $this->vender([['producto_id' => $this->producto()->id, 'cantidad' => 1]]);

        $venta->update(['total' => '999.00']);
        $this->assertBuilderRechaza($venta, 'no cuadran');

        $venta->update(['total' => '118.00', 'ncf' => 'E3100000001']);
        $this->assertBuilderRechaza($venta, 'formato');

        $venta->update(['ncf' => 'E320000000001', 'monto_gravado_18' => '-100.00']);
        $this->assertBuilderRechaza($venta, 'negativos');
    }

    private function assertBuilderRechaza(Venta $venta, string $mensaje): void
    {
        try {
            app(EcfBuilder::class)->construir($venta->fresh());
            $this->fail("El builder armó un e-CF inválido ({$mensaje}).");
        } catch (EcfInvalidoException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }

    /** Un e-NCF que la DGII ya tiene o está procesando, o de una venta anulada, no se reenvía. */
    public function test_no_se_reenvia_un_ecf_que_la_dgii_ya_tiene_ni_una_venta_anulada(): void
    {
        $venta = $this->vender([['producto_id' => $this->producto()->id, 'cantidad' => 1]]);

        $this->assertTrue(EnviarEcfJob::puedeEnviarse($venta));

        foreach ([EstadoFiscal::ACEPTADO, EstadoFiscal::ACEPTADO_CONDICIONAL, EstadoFiscal::RFCE, EstadoFiscal::EN_PROCESO] as $estado) {
            $venta->update(['estado_fiscal' => $estado]);
            $this->assertFalse(EnviarEcfJob::puedeEnviarse($venta), $estado->value);
        }

        $venta->update(['estado_fiscal' => EstadoFiscal::RECHAZADO]);
        $this->assertTrue(EnviarEcfJob::puedeEnviarse($venta), 'Un rechazo corregido sí se reenvía.');

        $venta->update(['estado' => EstadoVenta::ANULADA]);
        $this->assertFalse(EnviarEcfJob::puedeEnviarse($venta));

        // Y el job en sí no llama al PAC.
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub {});
        (new EnviarEcfJob($venta))->handle(app(EnvioEcfService::class));
        $this->assertSame(EstadoFiscal::RECHAZADO, $venta->fresh()->estado_fiscal);
    }

    // ------------------------------------------------------------------ anulación

    public function test_anular_exige_motivo(): void
    {
        $this->empresaDefault->config()->update(['permite_ventas_sin_comprobante' => true]);
        $venta = $this->vender([['producto_id' => $this->producto()->id, 'cantidad' => 1]], ['sin_comprobante' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('motivo');

        app(VentaService::class)->anular($venta, '   ');
    }

    // ------------------------------------------------------------------ compras

    public function test_compra_rechaza_cantidades_y_costos_negativos(): void
    {
        $proveedor = Proveedor::factory()->create(['empresa_id' => $this->empresaDefault->id]);
        $producto = $this->producto('VAL-COMPRA', extra: ['controla_stock' => true, 'stock' => 10]);
        $usuario = User::factory()->create();

        foreach ([['cantidad' => -5, 'costo_unitario' => 10], ['cantidad' => 5, 'costo_unitario' => -10]] as $linea) {
            try {
                app(CompraService::class)->crear([
                    'proveedor_id' => $proveedor->id,
                    'tipo_comprobante' => TipoComprobante::COMPRAS,
                    'ncf' => null,
                    'fecha' => now(),
                    'tipo_pago' => TipoPago::CONTADO->value,
                    'lineas' => [['producto_id' => $producto->id, ...$linea]],
                ], $usuario->id, $this->empresaDefault);
                $this->fail('Se registró una compra con '.json_encode($linea));
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/mayor que cero|negativo/', $e->getMessage());
            }
        }

        $this->assertEquals(10, (float) $producto->fresh()->stock);
    }
}
