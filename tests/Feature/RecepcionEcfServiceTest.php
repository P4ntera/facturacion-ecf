<?php

namespace Tests\Feature;

use App\Enums\CanalRecepcionEcf;
use App\Enums\EstadoReenvioPac;
use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\RecepcionEcfService;
use App\Services\Dgii\RespuestaEcf;
use App\Settings\EmpresaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecepcionEcfServiceTest extends TestCase
{
    use RefreshDatabase;

    private const RNC_EMPRESA = '130000000';

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EmpresaSettings::class);
        $settings->rnc = self::RNC_EMPRESA;
        $settings->save();
    }

    private function xml(string $rncComprador = self::RNC_EMPRESA, string $rncEmisor = '999999999'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <ECF>
            <Encabezado>
                <IdDoc>
                    <TipoeCF>32</TipoeCF>
                    <eNCF>E320000000099</eNCF>
                    <FechaEmision>09-07-2026</FechaEmision>
                </IdDoc>
                <Emisor>
                    <RNCEmisor>{$rncEmisor}</RNCEmisor>
                    <RazonSocialEmisor>Proveedor de Prueba SRL</RazonSocialEmisor>
                </Emisor>
                <Comprador>
                    <RNCComprador>{$rncComprador}</RNCComprador>
                </Comprador>
                <Totales>
                    <MontoTotal>1180.00</MontoTotal>
                </Totales>
            </Encabezado>
        </ECF>
        XML;
    }

    /** Gateway de prueba que devuelve una respuesta fija y nunca hace red. */
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

    public function test_reenvia_y_registra_un_documento_valido(): void
    {
        $this->conGateway(new RespuestaEcf(exito: true, responseJson: ['ok' => true]));

        $documento = app(RecepcionEcfService::class)->procesar(CanalRecepcionEcf::RECEPCION, $this->xml(), '203.0.113.5');

        $this->assertSame(EstadoReenvioPac::REENVIADO, $documento->estado_reenvio);
        $this->assertSame(CanalRecepcionEcf::RECEPCION, $documento->canal);
        $this->assertSame(self::RNC_EMPRESA, $documento->rnc_destino);
        $this->assertSame('999999999', $documento->rnc_emisor);
        $this->assertSame('Proveedor de Prueba SRL', $documento->razon_social_emisor);
        $this->assertSame('E320000000099', $documento->encf);
        $this->assertSame('32', $documento->tipo_comprobante);
        $this->assertSame('1180.00', $documento->monto_total);
        $this->assertSame('203.0.113.5', $documento->ip_origen);
        $this->assertDatabaseCount('documentos_recibidos', 1);
    }

    public function test_rechaza_sin_llamar_al_gateway_si_el_rnc_no_coincide(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class implements DgiiGatewayInterface
        {
            public function enviar(array $ecf): RespuestaEcf
            {
                throw new \RuntimeException('no debería llamarse');
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
                throw new \RuntimeException('no debería llamarse si el RNC no coincide');
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

        $documento = app(RecepcionEcfService::class)->procesar(
            CanalRecepcionEcf::RECEPCION,
            $this->xml(rncComprador: '111111111'),
            '203.0.113.5',
        );

        $this->assertSame(EstadoReenvioPac::RECHAZADO_VALIDACION, $documento->estado_reenvio);
        $this->assertStringContainsString('RNC', $documento->error);
    }

    public function test_rechaza_un_xml_que_supera_el_tamano_maximo(): void
    {
        $xmlEnorme = '<ECF>'.str_repeat('A', 6 * 1024 * 1024).'</ECF>';

        $documento = app(RecepcionEcfService::class)->procesar(CanalRecepcionEcf::RECEPCION, $xmlEnorme, null);

        $this->assertSame(EstadoReenvioPac::RECHAZADO_VALIDACION, $documento->estado_reenvio);
        $this->assertStringContainsString('tamaño', $documento->error);
    }

    public function test_marca_error_de_reenvio_si_el_pac_falla(): void
    {
        $this->conGateway(new RespuestaEcf(exito: false, errorMessage: 'PAC no disponible'));

        $documento = app(RecepcionEcfService::class)->procesar(CanalRecepcionEcf::RECEPCION, $this->xml(), null);

        $this->assertSame(EstadoReenvioPac::ERROR_REENVIO, $documento->estado_reenvio);
        $this->assertSame('PAC no disponible', $documento->error);
    }

    public function test_canal_aprobacion_comercial_se_registra_con_ese_canal(): void
    {
        $this->conGateway(new RespuestaEcf(exito: true));

        $documento = app(RecepcionEcfService::class)->procesar(
            CanalRecepcionEcf::APROBACION_COMERCIAL,
            $this->xml(),
            null,
        );

        $this->assertSame(CanalRecepcionEcf::APROBACION_COMERCIAL, $documento->canal);
        $this->assertSame(EstadoReenvioPac::REENVIADO, $documento->estado_reenvio);
    }
}
