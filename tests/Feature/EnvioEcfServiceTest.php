<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
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

class EnvioEcfServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * VentaObserver despacha EnviarEcfJob al crear la venta y, en pruebas, la cola es 'sync'
     * (se ejecutaría de inmediato con el gateway por defecto). Estas pruebas quieren controlar
     * ellas mismas qué gateway responde qué, así que se captura el job sin dejarlo correr.
     */
    private function crearVenta(TipoComprobante $tipo = TipoComprobante::FACTURA_CONSUMO, ?string $documentoCliente = null): Venta
    {
        Queue::fake();

        SecuenciaNcf::create([
            'tipo_comprobante' => $tipo,
            'prefijo' => 'E'.$tipo->value,
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $producto = Producto::create([
            'codigo' => 'ENV-'.$tipo->value,
            'nombre' => 'Producto envío',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $cliente = Cliente::create([
            'nombre' => 'Cliente envío',
            'documento' => $documentoCliente,
            'activo' => true,
        ]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => $tipo->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->refresh();
    }

    /** Sustituye el gateway por uno que devuelve siempre la misma RespuestaEcf dada. */
    private function conGateway(RespuestaEcf $respuesta): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class($respuesta) implements DgiiGatewayInterface
        {
            public function __construct(private readonly RespuestaEcf $respuesta) {}

            public function enviar(array $ecf): RespuestaEcf
            {
                return $this->respuesta;
            }

            public function consultarEstado(string $pacId): RespuestaEcf
            {
                return $this->respuesta;
            }

            public function consultarTrack(string $pacId): RespuestaEcf
            {
                return $this->respuesta;
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
                return $this->respuesta;
            }

            public function reenviarAprobacionComercial(string $xml): RespuestaEcf
            {
                return $this->respuesta;
            }

            public function registrarAprobacionComercial(array $datos): RespuestaEcf
            {
                return $this->respuesta;
            }
        });
    }

    public function test_respuesta_aceptada_actualiza_la_venta_con_los_datos_del_pac(): void
    {
        $venta = $this->crearVenta(documentoCliente: null);

        $this->conGateway(new RespuestaEcf(
            exito: true,
            pacId: 'PAC-1',
            estado: 'Aceptado',
            trackId: 'TRACK-1',
            codigoSeguridad: 'ABC123',
            dgiiUrl: 'https://dgii.test/timbre/1',
            xmlUrl: 'https://dgii.test/xml/1',
            responseJson: ['estado' => 'Aceptado'],
        ));

        app(EnvioEcfService::class)->enviar($venta);
        $venta->refresh();

        $this->assertSame(EstadoFiscal::ACEPTADO, $venta->estado_fiscal);
        $this->assertSame('PAC-1', $venta->pac_id);
        $this->assertSame('TRACK-1', $venta->ecf_track_id);
        $this->assertSame('ABC123', $venta->codigo_seguridad);
        $this->assertSame('https://dgii.test/timbre/1', $venta->dgii_url);
        $this->assertSame('https://dgii.test/xml/1', $venta->xml_url);
        $this->assertNotNull($venta->ecf_enviado_en);
    }

    public function test_respuesta_rechazada_del_pac_marca_la_venta_como_rechazada(): void
    {
        $venta = $this->crearVenta(documentoCliente: null);

        $this->conGateway(new RespuestaEcf(exito: true, estado: 'Rechazado', errorMessage: 'RNC inválido'));

        app(EnvioEcfService::class)->enviar($venta);

        $this->assertSame(EstadoFiscal::RECHAZADO, $venta->refresh()->estado_fiscal);
    }

    public function test_respuesta_en_proceso_del_pac_deja_la_venta_en_proceso(): void
    {
        $venta = $this->crearVenta(documentoCliente: null);

        $this->conGateway(new RespuestaEcf(exito: true, estado: 'En Proceso'));

        app(EnvioEcfService::class)->enviar($venta);

        $this->assertSame(EstadoFiscal::EN_PROCESO, $venta->refresh()->estado_fiscal);
    }

    public function test_error_de_red_deja_la_venta_pendiente_con_el_motivo_guardado(): void
    {
        $venta = $this->crearVenta(documentoCliente: null);

        $this->conGateway(new RespuestaEcf(exito: false, errorMessage: 'No se pudo conectar con el PAC.'));

        $respuesta = app(EnvioEcfService::class)->enviar($venta);
        $venta->refresh();

        $this->assertFalse($respuesta->exito);
        $this->assertSame(EstadoFiscal::PENDIENTE, $venta->estado_fiscal);
        $this->assertSame('No se pudo conectar con el PAC.', $venta->ecf_respuesta['error']);
    }

    public function test_falta_de_rnc_en_credito_fiscal_rechaza_sin_llamar_al_gateway(): void
    {
        // VentaService::registrar() ya exige RNC para el 31 al cobrar; se crea con uno válido y
        // se le quita después, para probar la defensa "en profundidad" del builder ante una
        // venta que de algún modo (edición posterior del cliente, dato legado) llega sin RNC.
        $venta = $this->crearVenta(TipoComprobante::FACTURA_CREDITO_FISCAL, documentoCliente: '130000000');
        $venta->cliente->update(['documento' => null]);

        $this->app->bind(DgiiGatewayInterface::class, fn () => new class implements DgiiGatewayInterface
        {
            public function enviar(array $ecf): RespuestaEcf
            {
                throw new \RuntimeException('No debería llamarse al gateway si el e-CF es inválido.');
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

        app(EnvioEcfService::class)->enviar($venta);

        $venta->refresh();
        $this->assertSame(EstadoFiscal::RECHAZADO, $venta->estado_fiscal);
        $this->assertStringContainsString('RNC', $venta->ecf_respuesta['error']);
    }
}
