<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\Venta;
use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\RespuestaEcf;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GatewayStub;
use Tests\TestCase;

/**
 * Red de seguridad del envío de e-CF (ecf:procesar-pendientes, cada 5 minutos): reencola los
 * pendientes que se quedaron sin enviar y consulta el estado de los que están en proceso.
 */
class ProcesarEcfPendientesTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'ECF-1', 'nombre' => 'Producto e-CF', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50, 'precio' => 100, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 100, 'stock_minimo' => 1, 'activo' => true,
        ]);
        $this->cliente = Cliente::create(['nombre' => 'Cliente e-CF', 'activo' => true]);
    }

    private function ventaElectronica(): Venta
    {
        return app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault)->refresh();
    }

    /** Simula que el último intento (o la última novedad) fue hace $minutos. */
    private function envejecer(Venta $venta, int $minutos): void
    {
        DB::table('ventas')->where('id', $venta->id)->update(['updated_at' => now()->subMinutes($minutos)]);
    }

    private function enProceso(string $pacId, int $minutos = 5): Venta
    {
        $venta = $this->ventaElectronica();
        $venta->update(['estado_fiscal' => EstadoFiscal::EN_PROCESO, 'pac_id' => $pacId]);
        $this->envejecer($venta, $minutos);

        return $venta;
    }

    public function test_reencola_solo_los_pendientes_que_llevan_rato_sin_salir(): void
    {
        $vieja = $this->ventaElectronica();
        $this->envejecer($vieja, 15);

        $reciente = $this->ventaElectronica(); // su job puede estar todavía en backoff
        $this->envejecer($reciente, 3);

        $anulada = $this->ventaElectronica();
        DB::table('ventas')->where('id', $anulada->id)->update(['estado' => EstadoVenta::ANULADA->value, 'updated_at' => now()->subHour()]);

        $aceptada = $this->ventaElectronica();
        DB::table('ventas')->where('id', $aceptada->id)->update(['estado_fiscal' => EstadoFiscal::ACEPTADO->value, 'updated_at' => now()->subHour()]);

        Queue::fake(); // descarta los envíos de cuando se registraron las ventas

        $this->artisan('ecf:procesar-pendientes')->assertSuccessful();

        Queue::assertPushed(EnviarEcfJob::class, 1);
        Queue::assertPushed(EnviarEcfJob::class, fn (EnviarEcfJob $job) => $job->venta->is($vieja));
    }

    public function test_consulta_el_estado_de_los_que_estan_en_proceso(): void
    {
        $venta = $this->enProceso('PAC-1');
        $reciente = $this->enProceso('PAC-2', minutos: 1);

        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function consultarTrack(string $pacId): RespuestaEcf
            {
                if ($pacId !== 'PAC-1') {
                    throw new \RuntimeException("No debía consultarse {$pacId}: se acaba de actualizar.");
                }

                return new RespuestaEcf(exito: true, pacId: $pacId, estado: 'Aceptado');
            }
        });

        $this->artisan('ecf:procesar-pendientes')->assertSuccessful();

        $this->assertSame(EstadoFiscal::ACEPTADO, $venta->fresh()->estado_fiscal);
        $this->assertSame(EstadoFiscal::EN_PROCESO, $reciente->fresh()->estado_fiscal);
    }

    public function test_si_una_consulta_falla_las_demas_siguen(): void
    {
        $falla = $this->enProceso('PAC-CAIDO');
        $bien = $this->enProceso('PAC-OK');

        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function consultarTrack(string $pacId): RespuestaEcf
            {
                if ($pacId === 'PAC-CAIDO') {
                    throw new \RuntimeException('Timeout del PAC');
                }

                return new RespuestaEcf(exito: true, pacId: $pacId, estado: 'Aceptado');
            }
        });

        $this->artisan('ecf:procesar-pendientes')
            ->expectsOutputToContain('Consultas fallidas: 1')
            ->assertSuccessful();

        $this->assertSame(EstadoFiscal::EN_PROCESO, $falla->fresh()->estado_fiscal);
        $this->assertSame(EstadoFiscal::ACEPTADO, $bien->fresh()->estado_fiscal);
    }

    public function test_no_se_encolan_dos_envios_de_la_misma_venta(): void
    {
        $venta = $this->ventaElectronica();
        Queue::fake();

        EnviarEcfJob::dispatch($venta);
        EnviarEcfJob::dispatch($venta);

        Queue::assertPushed(EnviarEcfJob::class, 1);
    }

    public function test_el_job_va_a_la_cola_ecf(): void
    {
        $this->ventaElectronica();

        Queue::assertPushedOn('ecf', EnviarEcfJob::class);
    }

    public function test_el_comando_esta_programado_cada_cinco_minutos(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('ecf:procesar-pendientes')
            ->assertSuccessful();
    }
}
