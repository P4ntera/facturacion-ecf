<?php

namespace Tests\Feature;

use App\Enums\AmbienteEcf;
use App\Models\Empresa;
use App\Services\Dgii\DgiiGatewayFactory;
use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\EcfPlatformGateway;
use App\Services\Dgii\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DgiiGatewayTest extends TestCase
{
    use RefreshDatabase;

    /** EcfPlatformGateway ya no se resuelve del contenedor: se construye para UNA empresa. */
    private function empresa(array $configuracion = []): Empresa
    {
        $empresa = $this->empresaDefault ?? Empresa::factory()->create();
        $empresa->config()->update($configuracion);

        return $empresa;
    }

    public function test_el_contenedor_resuelve_al_fake_gateway_por_defecto_en_pruebas(): void
    {
        $this->assertInstanceOf(FakeGateway::class, app(DgiiGatewayInterface::class));
    }

    public function test_fake_gateway_acepta_sin_llamar_a_la_red(): void
    {
        Http::preventStrayRequests();

        $respuesta = app(FakeGateway::class)->enviar(['encf' => 'E320000000001']);

        $this->assertTrue($respuesta->exito);
        $this->assertSame('Aceptado', $respuesta->estado);
        $this->assertSame('E320000000001', $respuesta->encf);
        $this->assertNotNull($respuesta->trackId);
        $this->assertNotNull($respuesta->dgiiUrl);
    }

    public function test_ecf_platform_gateway_no_lanza_ante_un_error_de_red_y_no_registra_la_api_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        Log::spy();

        $empresa = $this->empresa(['dgii_api_key' => 'clave-secreta-de-prueba']);

        $respuesta = (new EcfPlatformGateway($empresa))->enviar(['encf' => 'E320000000001']);

        $this->assertFalse($respuesta->exito);
        $this->assertNotNull($respuesta->errorMessage);

        Log::shouldNotHaveReceived('error', function (string $mensaje, array $contexto = []) {
            return str_contains($mensaje.json_encode($contexto), 'clave-secreta-de-prueba');
        });
    }

    public function test_ecf_platform_gateway_mapea_una_respuesta_exitosa_del_pac(): void
    {
        Http::fake(['*' => Http::response([
            'pacId' => 'PAC-123',
            'encf' => 'E320000000001',
            'estado' => 'Aceptado',
            'trackId' => 'TRACK-1',
            'ambiente' => 'TesteCF',
        ], 200)]);

        $respuesta = (new EcfPlatformGateway($this->empresa()))->enviar(['encf' => 'E320000000001']);

        $this->assertTrue($respuesta->exito);
        $this->assertSame('PAC-123', $respuesta->pacId);
        $this->assertSame('Aceptado', $respuesta->estado);
        $this->assertTrue($respuesta->ambiente->esProduccion() === false);
    }

    public function test_forzar_real_en_local_usa_gateway_real_solo_con_testecf(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config(['dgii.fake' => false, 'dgii.forzar_real' => true]);

        $empresa = $this->empresa(['dgii_ambiente' => AmbienteEcf::TESTECF]);
        $factory = app(DgiiGatewayFactory::class);

        $this->assertInstanceOf(EcfPlatformGateway::class, $factory->make($empresa));
    }

    public function test_forzar_real_en_local_con_ambiente_produccion_sigue_usando_fake(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config(['dgii.fake' => false, 'dgii.forzar_real' => true]);

        $empresa = $this->empresa(['dgii_ambiente' => AmbienteEcf::ECF]);
        $factory = app(DgiiGatewayFactory::class);

        $this->assertInstanceOf(FakeGateway::class, $factory->make($empresa));
    }

    public function test_sin_forzar_real_en_local_siempre_usa_fake(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config(['dgii.fake' => false, 'dgii.forzar_real' => false]);

        $empresa = $this->empresa(['dgii_ambiente' => AmbienteEcf::TESTECF]);
        $factory = app(DgiiGatewayFactory::class);

        $this->assertInstanceOf(FakeGateway::class, $factory->make($empresa));
    }
}
