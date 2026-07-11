<?php

namespace Tests\Feature;

use App\Enums\TipoDocumentoCliente;
use App\Services\Dgii\ConsultaContribuyenteService;
use App\Services\Dgii\DgiiGatewayInterface;
use Tests\Support\GatewayStub;
use Tests\TestCase;

class ConsultaContribuyenteServiceTest extends TestCase
{
    public function test_busca_por_rnc_de_9_digitos(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarContribuyente(string $valor): ?array
            {
                return ['rnc' => $valor, 'nombre' => 'Comercial Prueba SRL', 'estado' => 'ACTIVO'];
            }
        });

        $resultado = app(ConsultaContribuyenteService::class)->buscar('130123456');

        $this->assertSame('130123456', $resultado['documento']);
        $this->assertSame('Comercial Prueba SRL', $resultado['nombre']);
        $this->assertSame(TipoDocumentoCliente::RNC, $resultado['tipo']);
    }

    public function test_busca_por_cedula_de_11_digitos(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarCedulaJce(string $cedula): ?array
            {
                return ['cedula' => $cedula, 'nombre' => 'Juan Pérez', 'estado' => 'ACTIVA'];
            }
        });

        $resultado = app(ConsultaContribuyenteService::class)->buscar('00112345678');

        $this->assertSame('00112345678', $resultado['documento']);
        $this->assertSame('Juan Pérez', $resultado['nombre']);
        $this->assertSame(TipoDocumentoCliente::CEDULA, $resultado['tipo']);
    }

    public function test_ignora_guiones_y_espacios_en_el_documento(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarContribuyente(string $valor): ?array
            {
                return ['rnc' => $valor, 'nombre' => 'Comercial Prueba SRL'];
            }
        });

        $resultado = app(ConsultaContribuyenteService::class)->buscar('1-30123456');

        $this->assertSame('130123456', $resultado['documento']);
    }

    public function test_null_si_el_documento_no_tiene_9_ni_11_digitos(): void
    {
        // Ningún método del gateway debería llamarse: GatewayStub lanza si se invoca alguno.
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub {});

        $resultado = app(ConsultaContribuyenteService::class)->buscar('12345');

        $this->assertNull($resultado);
    }

    public function test_null_si_no_se_encuentra_en_la_dgii(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarContribuyente(string $valor): ?array
            {
                return null;
            }
        });

        $this->assertNull(app(ConsultaContribuyenteService::class)->buscar('130123456'));
    }

    public function test_null_si_el_resultado_no_trae_nombre(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarContribuyente(string $valor): ?array
            {
                return ['rnc' => $valor];
            }
        });

        $this->assertNull(app(ConsultaContribuyenteService::class)->buscar('130123456'));
    }
}
