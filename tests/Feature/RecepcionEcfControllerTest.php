<?php

namespace Tests\Feature;

use App\Models\DocumentoRecibido;
use App\Settings\EmpresaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecepcionEcfControllerTest extends TestCase
{
    use RefreshDatabase;

    private const RNC_EMPRESA = '130000000';

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EmpresaSettings::class);
        $settings->rnc = self::RNC_EMPRESA;
        $settings->dgii_api_key = 'clave-de-prueba';
        $settings->dgii_base_url = 'https://pac.test';
        $settings->save();

        // FakeGateway se usa en 'local'/'testing' salvo que se fuerce lo contrario; aquí queremos
        // ejercitar EcfPlatformGateway real para probar el reenvío HTTP tal cual.
        config(['dgii.fake' => false]);
    }

    private function xml(string $rncComprador = self::RNC_EMPRESA): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <ECF>
            <Encabezado>
                <IdDoc><TipoeCF>32</TipoeCF><eNCF>E320000000099</eNCF></IdDoc>
                <Emisor><RNCEmisor>999999999</RNCEmisor><RazonSocialEmisor>Proveedor</RazonSocialEmisor></Emisor>
                <Comprador><RNCComprador>{$rncComprador}</RNCComprador></Comprador>
                <Totales><MontoTotal>1180.00</MontoTotal></Totales>
            </Encabezado>
        </ECF>
        XML;
    }

    public function test_recepcion_reenvia_el_xml_crudo_al_pac_con_la_api_key(): void
    {
        Http::fake(['*' => Http::response(['estado' => 'Aceptado'], 200)]);

        $xml = $this->xml();

        $response = $this->call('POST', '/fe/recepcion/api/ecf', server: [
            'CONTENT_TYPE' => 'application/xml',
        ], content: $xml);

        $response->assertOk();
        $response->assertJson(['status' => 'recibido']);

        Http::assertSent(function ($request) use ($xml) {
            return $request->url() === 'https://pac.test/'.self::RNC_EMPRESA.'/fe/recepcion/api/ecf'
                && $request->hasHeader('X-API-Key', 'clave-de-prueba')
                && $request->body() === $xml;
        });

        $this->assertDatabaseCount('documentos_recibidos', 1);
        $this->assertSame('reenviado', DocumentoRecibido::first()->estado_reenvio->value);
    }

    public function test_recepcion_acepta_multipart_con_campo_xml(): void
    {
        Http::fake(['*' => Http::response(['estado' => 'Aceptado'], 200)]);

        $archivo = UploadedFile::fake()->createWithContent('ecf.xml', $this->xml());

        $response = $this->post('/fe/recepcion/api/ecf', ['xml' => $archivo]);

        $response->assertOk();
        $this->assertDatabaseCount('documentos_recibidos', 1);
    }

    public function test_rechaza_con_422_si_el_rnc_no_coincide(): void
    {
        $response = $this->call('POST', '/fe/recepcion/api/ecf', server: [
            'CONTENT_TYPE' => 'application/xml',
        ], content: $this->xml(rncComprador: '111111111'));

        $response->assertStatus(422);
        $this->assertSame('rechazado_validacion', DocumentoRecibido::first()->estado_reenvio->value);
    }

    public function test_rechaza_con_422_si_no_hay_xml(): void
    {
        $response = $this->postJson('/fe/recepcion/api/ecf', []);

        $response->assertStatus(422);
    }

    public function test_502_si_el_pac_no_responde(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $response = $this->call('POST', '/fe/recepcion/api/ecf', server: [
            'CONTENT_TYPE' => 'application/xml',
        ], content: $this->xml());

        $response->assertStatus(502);
    }

    public function test_aprobacion_comercial_reenvia_al_segmento_correcto_del_pac(): void
    {
        Http::fake(['*' => Http::response(['estado' => 'Aceptado'], 200)]);

        $response = $this->call('POST', '/fe/aprobacioncomercial/api/ecf', server: [
            'CONTENT_TYPE' => 'application/xml',
        ], content: $this->xml());

        $response->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://pac.test/'.self::RNC_EMPRESA.'/fe/aprobacioncomercial/api/ecf');

        $this->assertSame('aprobacion_comercial', DocumentoRecibido::first()->canal->value);
    }

    public function test_limita_la_cantidad_de_peticiones_por_minuto(): void
    {
        Http::fake(['*' => Http::response(['estado' => 'Aceptado'], 200)]);

        for ($i = 0; $i < 30; $i++) {
            $this->call('POST', '/fe/recepcion/api/ecf', server: [
                'CONTENT_TYPE' => 'application/xml',
            ], content: $this->xml())->assertOk();
        }

        $response = $this->call('POST', '/fe/recepcion/api/ecf', server: [
            'CONTENT_TYPE' => 'application/xml',
        ], content: $this->xml());

        $response->assertStatus(429);
    }
}
