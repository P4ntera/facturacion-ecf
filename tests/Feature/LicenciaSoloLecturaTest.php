<?php

namespace Tests\Feature;

use App\Exceptions\LicenciaSoloLecturaException;
use App\Filament\Pages\ManageEmpresa;
use App\Http\Middleware\VerificarLicencia;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\EmpresaLicenseState;
use App\Services\LicenseService;
use App\Support\LicenciaSoloLectura;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Mechanisms\ComponentRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LicenciaSoloLecturaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private string $clavePrivada;

    protected function setUp(): void
    {
        parent::setUp();

        $par = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($par, $privada);
        $this->clavePrivada = $privada;

        config([
            'license.enabled' => true,
            'license.public_key' => openssl_pkey_get_details($par)['key'],
            'license.api_url' => 'https://licencias.test/validate',
            'license.anon_key' => 'anon',
        ]);

        $this->empresa = Empresa::create(['razon_social' => 'Empresa Licencia', 'rnc' => '131888001']);
        Filament::setTenant($this->empresa, isQuiet: true);
    }

    protected function tearDown(): void
    {
        app(LicenciaSoloLectura::class)->desactivar();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function b64(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $claims */
    private function firmar(array $claims, string $alg = 'RS256'): string
    {
        $cabecera = $this->b64(json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $cuerpo = $this->b64(json_encode($claims));
        openssl_sign("{$cabecera}.{$cuerpo}", $firma, $this->clavePrivada, OPENSSL_ALGO_SHA256);

        return "{$cabecera}.{$cuerpo}.".$this->b64($firma);
    }

    private function licenciaValida(): void
    {
        EmpresaLicenseState::create([
            'empresa_id' => $this->empresa->id,
            'license_key' => 'LIC-OK',
            'status' => 'valid',
            'license_expires_at' => now()->addMonth(),
        ]);
    }

    private function requestLivewire(string $metodo, string $componente = 'app.filament.pages.punto-de-venta'): Request
    {
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => $componente]]),
                'calls' => [['method' => $metodo, 'params' => []]],
            ]],
        ]);
        $request->headers->set('X-Livewire', 'true');

        return $request;
    }

    private function crearCategoria(string $nombre = 'Bloqueada'): Categoria
    {
        return Categoria::create(['empresa_id' => $this->empresa->id, 'nombre' => $nombre, 'activo' => true]);
    }

    // ---------------------------------------------------------------- candado

    public function test_reconoce_la_tabla_que_escribe_cada_sentencia(): void
    {
        $this->assertSame('ventas', LicenciaSoloLectura::tablaEscrita('insert into "ventas" ("id") values (?)'));
        $this->assertSame('productos', LicenciaSoloLectura::tablaEscrita('update "productos" set "stock" = ?'));
        $this->assertSame('cajas', LicenciaSoloLectura::tablaEscrita('  DELETE FROM "public"."cajas" where id = ?'));
        $this->assertNull(LicenciaSoloLectura::tablaEscrita('select * from "ventas" where "id" = ? for update'));
    }

    public function test_con_el_candado_activo_no_se_escriben_tablas_de_negocio(): void
    {
        app(LicenciaSoloLectura::class)->activar();

        try {
            $this->crearCategoria();
            $this->fail('La escritura debió bloquearse.');
        } catch (LicenciaSoloLecturaException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Categoria::count());
    }

    public function test_con_el_candado_activo_se_puede_leer_y_escribir_el_estado_de_licencia(): void
    {
        app(LicenciaSoloLectura::class)->activar();

        EmpresaLicenseState::updateOrCreate(['empresa_id' => $this->empresa->id], ['license_key' => 'LIC-NUEVA']);

        $this->assertSame('LIC-NUEVA', EmpresaLicenseState::where('empresa_id', $this->empresa->id)->value('license_key'));
        $this->assertSame(1, Empresa::whereKey($this->empresa->id)->count());
    }

    public function test_al_desactivar_el_candado_se_vuelve_a_escribir(): void
    {
        $candado = app(LicenciaSoloLectura::class);
        $candado->activar();
        $candado->desactivar();

        $this->crearCategoria('Permitida');

        $this->assertSame(1, Categoria::count());
    }

    // ------------------------------------------------------------- middleware

    public function test_sin_licencia_valida_un_metodo_propio_como_cobrar_no_logra_escribir(): void
    {
        $llegoAlComponente = false;

        try {
            app(VerificarLicencia::class)->handle($this->requestLivewire('cobrar'), function () use (&$llegoAlComponente) {
                $llegoAlComponente = true;
                $this->crearCategoria();

                return new Response;
            });
            $this->fail('El cobro debió quedar bloqueado.');
        } catch (LicenciaSoloLecturaException) {
            $this->assertTrue($llegoAlComponente);
        }

        $this->assertSame(0, Categoria::count());
    }

    public function test_sin_licencia_valida_navegar_sigue_funcionando(): void
    {
        $respuesta = app(VerificarLicencia::class)->handle(
            $this->requestLivewire('gotoPage'),
            fn () => new Response((string) Empresa::count())
        );

        $this->assertSame(200, $respuesta->getStatusCode());
    }

    public function test_sin_licencia_valida_guardar_se_corta_antes_de_llegar_al_componente(): void
    {
        $this->expectException(HttpException::class);

        app(VerificarLicencia::class)->handle(
            $this->requestLivewire('save'),
            fn () => $this->fail('No debió llegar al componente.')
        );
    }

    public function test_revisa_las_llamadas_de_todos_los_componentes_de_la_request(): void
    {
        $request = $this->requestLivewire('gotoPage');
        $componentes = $request->input('components');
        $componentes[] = ['snapshot' => $componentes[0]['snapshot'], 'calls' => [['method' => 'save', 'params' => []]]];
        $request->merge(['components' => $componentes]);

        $this->expectException(HttpException::class);

        app(VerificarLicencia::class)->handle($request, fn () => $this->fail('No debió llegar al componente.'));
    }

    public function test_la_pagina_de_datos_de_la_empresa_puede_guardar_para_registrar_la_clave(): void
    {
        $nombre = app(ComponentRegistry::class)->getName(ManageEmpresa::class);

        $respuesta = app(VerificarLicencia::class)->handle($this->requestLivewire('save', $nombre), function () {
            $this->empresa->update(['telefono' => '809-555-0000']);

            return new Response;
        });

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertFalse(app(LicenciaSoloLectura::class)->activo());
        $this->assertSame('809-555-0000', $this->empresa->fresh()->telefono);
    }

    public function test_con_licencia_valida_no_se_bloquea_nada(): void
    {
        $this->licenciaValida();

        app(VerificarLicencia::class)->handle($this->requestLivewire('cobrar'), function () {
            $this->crearCategoria('Vendida');

            return new Response;
        });

        $this->assertFalse(app(LicenciaSoloLectura::class)->activo());
        $this->assertSame(1, Categoria::count());
    }

    public function test_con_la_validacion_desactivada_no_se_bloquea_nada(): void
    {
        config(['license.enabled' => false]);

        app(VerificarLicencia::class)->handle($this->requestLivewire('save'), function () {
            $this->crearCategoria('Libre');

            return new Response;
        });

        $this->assertSame(1, Categoria::count());
    }

    // ------------------------------------------------------------ firma / API

    public function test_verifica_la_firma_rs256_sin_paquetes_externos(): void
    {
        $token = $this->firmar(['exp' => time() + 3600, 'license_expires_at' => now()->addYear()->toIso8601String()]);

        $resultado = app(LicenseService::class)->verifyLocal($token);

        $this->assertTrue($resultado['valid']);
        $this->assertEqualsWithDelta(time() + 3600, $resultado['claims']->exp, 5);
    }

    public function test_rechaza_tokens_alterados_vencidos_o_con_otro_algoritmo(): void
    {
        $servicio = app(LicenseService::class);
        $valido = $this->firmar(['exp' => time() + 3600]);

        [$cabecera, , $firma] = explode('.', $valido);
        $alterado = $cabecera.'.'.$this->b64(json_encode(['exp' => time() + 999999])).'.'.$firma;

        $this->assertFalse($servicio->verifyLocal($alterado)['valid']);
        $this->assertFalse($servicio->verifyLocal($this->firmar(['exp' => time() - 3600]))['valid']);
        $this->assertFalse($servicio->verifyLocal($this->firmar(['exp' => time() + 3600], 'none'))['valid']);
        $this->assertFalse($servicio->verifyLocal($this->firmar(['exp' => time() + 3600], 'HS256'))['valid']);
        $this->assertFalse($servicio->verifyLocal('no-es-un-token')['valid']);
    }

    public function test_acepta_la_clave_publica_en_una_sola_linea_como_va_en_el_env(): void
    {
        config(['license.public_key' => str_replace("\n", '\n', (string) config('license.public_key'))]);

        $this->assertTrue(app(LicenseService::class)->verifyLocal($this->firmar(['exp' => time() + 3600]))['valid']);
    }

    public function test_refrescar_con_un_token_valido_deja_la_licencia_vigente(): void
    {
        EmpresaLicenseState::create(['empresa_id' => $this->empresa->id, 'license_key' => 'LIC-1', 'status' => 'unknown']);

        Http::fake(['licencias.test/*' => Http::response([
            'valid' => true,
            'token' => $this->firmar(['exp' => time() + 172800, 'license_expires_at' => now()->addYear()->toIso8601String()]),
        ])]);

        $resultado = app(LicenseService::class)->refreshForEmpresa($this->empresa);

        $this->assertTrue($resultado['valid']);
        $this->assertTrue(app(LicenseService::class)->stateForEmpresa($this->empresa)['valid']);
    }

    public function test_sin_conexion_una_licencia_ya_validada_sigue_vigente(): void
    {
        $this->licenciaValida();

        Http::fake(fn () => throw new ConnectionException('sin red'));

        $resultado = app(LicenseService::class)->refreshForEmpresa($this->empresa);

        $this->assertTrue($resultado['valid']);
        $this->assertSame('network_error', $resultado['reason']);
    }
}
