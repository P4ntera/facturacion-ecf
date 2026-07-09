<?php

namespace Tests\Feature;

use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\EcfPlatformGateway;
use App\Services\Dgii\FakeGateway;
use App\Settings\EmpresaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DgiiGatewayTest extends TestCase
{
    use RefreshDatabase;

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

        $settings = app(EmpresaSettings::class);
        $settings->dgii_api_key = 'clave-secreta-de-prueba';
        $settings->save();

        $respuesta = app(EcfPlatformGateway::class)->enviar(['encf' => 'E320000000001']);

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

        $respuesta = app(EcfPlatformGateway::class)->enviar(['encf' => 'E320000000001']);

        $this->assertTrue($respuesta->exito);
        $this->assertSame('PAC-123', $respuesta->pacId);
        $this->assertSame('Aceptado', $respuesta->estado);
        $this->assertTrue($respuesta->ambiente->esProduccion() === false);
    }
}
