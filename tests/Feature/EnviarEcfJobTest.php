<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Exceptions\DgiiGatewayException;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\Venta;
use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\EnvioEcfService;
use App\Services\Dgii\RespuestaEcf;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnviarEcfJobTest extends TestCase
{
    use RefreshDatabase;

    private function crearVentaPendiente(): Venta
    {
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

        $producto = Producto::create([
            'codigo' => 'JOB-1',
            'nombre' => 'Producto job',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $cliente = Cliente::create(['nombre' => 'Cliente job', 'activo' => true]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->refresh();
    }

    public function test_no_reenvia_si_la_venta_ya_esta_aceptada(): void
    {
        $venta = $this->crearVentaPendiente();
        $venta->update(['estado_fiscal' => EstadoFiscal::ACEPTADO]);

        $this->app->bind(DgiiGatewayInterface::class, fn () => new class implements DgiiGatewayInterface
        {
            public function enviar(array $ecf): RespuestaEcf
            {
                throw new \RuntimeException('No debía reenviarse una venta ya aceptada.');
            }

            public function consultarEstado(string $pacId): RespuestaEcf
            {
                throw new \RuntimeException('no usado');
            }

            public function consultarTrack(string $pacId): RespuestaEcf
            {
                throw new \RuntimeException('no usado');
            }

            public function descargarXml(string $pacId): string
            {
                throw new \RuntimeException('no usado');
            }

            public function buscarContribuyente(string $valor): ?array
            {
                throw new \RuntimeException('no usado');
            }

            public function buscarCedulaJce(string $cedula): ?array
            {
                throw new \RuntimeException('no usado');
            }

            public function reenviarRecepcion(string $xml): RespuestaEcf
            {
                throw new \RuntimeException('no usado');
            }

            public function reenviarAprobacionComercial(string $xml): RespuestaEcf
            {
                throw new \RuntimeException('no usado');
            }

            public function registrarAprobacionComercial(array $datos): RespuestaEcf
            {
                throw new \RuntimeException('no usado');
            }
        });

        (new EnviarEcfJob($venta))->handle(app(EnvioEcfService::class));

        $this->assertTrue(true); // si el gateway se hubiera llamado, la excepción arriba habría fallado el test.
    }

    public function test_relanza_la_excepcion_ante_un_error_de_red_para_que_el_job_reintente(): void
    {
        $venta = $this->crearVentaPendiente();

        $this->app->bind(DgiiGatewayInterface::class, fn () => new class implements DgiiGatewayInterface
        {
            public function enviar(array $ecf): RespuestaEcf
            {
                return new RespuestaEcf(exito: false, errorMessage: 'timeout');
            }

            public function consultarEstado(string $pacId): RespuestaEcf
            {
                return new RespuestaEcf(exito: false);
            }

            public function consultarTrack(string $pacId): RespuestaEcf
            {
                return new RespuestaEcf(exito: false);
            }

            public function descargarXml(string $pacId): string
            {
                return '';
            }

            public function buscarContribuyente(string $valor): ?array
            {
                return null;
            }

            public function buscarCedulaJce(string $cedula): ?array
            {
                return null;
            }

            public function reenviarRecepcion(string $xml): RespuestaEcf
            {
                return new RespuestaEcf(exito: false);
            }

            public function reenviarAprobacionComercial(string $xml): RespuestaEcf
            {
                return new RespuestaEcf(exito: false);
            }

            public function registrarAprobacionComercial(array $datos): RespuestaEcf
            {
                return new RespuestaEcf(exito: false);
            }
        });

        $this->expectException(DgiiGatewayException::class);

        (new EnviarEcfJob($venta))->handle(app(EnvioEcfService::class));
    }

    public function test_no_relanza_si_el_rechazo_es_por_datos_invalidos(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL,
            'prefijo' => 'E31',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        Queue::fake();

        $producto = Producto::create([
            'codigo' => 'JOB-2',
            'nombre' => 'Producto job 2',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        // VentaService::registrar() ya exige RNC para el 31 al cobrar; se crea con uno válido y
        // se le quita después, para probar la defensa "en profundidad" del builder ante una
        // venta que de algún modo (edición posterior del cliente, dato legado) llega sin RNC.
        $cliente = Cliente::create(['nombre' => 'Con RNC luego retirado', 'documento' => '130000000', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->refresh();

        $cliente->update(['documento' => null]);

        (new EnviarEcfJob($venta))->handle(app(EnvioEcfService::class));

        $this->assertSame(EstadoFiscal::RECHAZADO, $venta->refresh()->estado_fiscal);
    }

    public function test_al_registrar_la_venta_se_dispara_el_job_a_la_cola_ecf(): void
    {
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

        $producto = Producto::create([
            'codigo' => 'JOB-3',
            'nombre' => 'Producto job 3',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $cliente = Cliente::create(['nombre' => 'Cliente dispatch', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        Queue::assertPushedOn('ecf', EnviarEcfJob::class, fn (EnviarEcfJob $job) => $job->venta->is($venta));
    }

    /**
     * Extremo a extremo con el FakeGateway real (sin Queue::fake ni gateway de prueba): cobrar
     * una venta la deja PENDIENTE y, al procesarse el job (en pruebas la cola es 'sync', corre en
     * el mismo request — en producción lo haría el worker), pasa a ACEPTADO con trackId y dgii_url.
     */
    public function test_end_to_end_con_fake_gateway_pasa_de_pendiente_a_aceptado(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $producto = Producto::create([
            'codigo' => 'JOB-4',
            'nombre' => 'Producto job 4',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $cliente = Cliente::create(['nombre' => 'Cliente end to end', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        // La cola 'sync' de pruebas ya corrió el job; se refresca para leer el resultado final.
        $venta->refresh();

        $this->assertSame(EstadoFiscal::ACEPTADO, $venta->estado_fiscal);
        $this->assertNotNull($venta->ecf_track_id);
        $this->assertNotNull($venta->dgii_url);
    }
}
