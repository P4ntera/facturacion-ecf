<?php

namespace Tests\Feature;

use App\Enums\AnchoPapel;
use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\ModuloImpresion;
use App\Enums\TipoComprobante;
use App\Enums\TipoConexionImpresora;
use App\Enums\TipoDocumentoCliente;
use App\Models\Cliente;
use App\Models\Impresora;
use App\Models\User;
use App\Models\Venta;
use App\Services\Impresion\ImpresionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpresionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function crearVenta(): Venta
    {
        $cliente = Cliente::create([
            'tipo_documento' => TipoDocumentoCliente::CEDULA,
            'documento' => '00112345678',
            'nombre' => 'Cliente ticket',
            'activo' => true,
        ]);

        return Venta::create([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'ncf' => 'E320000000050',
            'fecha' => now(),
            'subtotal' => '100.00',
            'total_itbis' => '18.00',
            'total' => '118.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ]);
    }

    public function test_resolver_impresora_devuelve_null_sin_impresoras_configuradas(): void
    {
        $resultado = app(ImpresionService::class)->resolverImpresora(ModuloImpresion::FACTURACION, null);

        $this->assertNull($resultado);
    }

    public function test_resolver_impresora_usa_la_predeterminada_del_modulo_sin_usuario(): void
    {
        $predeterminada = Impresora::create([
            'nombre' => 'Predeterminada',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        $resultado = app(ImpresionService::class)->resolverImpresora(ModuloImpresion::FACTURACION, null);

        $this->assertSame($predeterminada->id, $resultado?->id);
    }

    public function test_resolver_impresora_prioriza_la_del_usuario_sobre_la_predeterminada(): void
    {
        Impresora::create([
            'nombre' => 'Predeterminada',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        $propia = Impresora::create([
            'nombre' => 'Del cajero',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
        ]);

        $usuario = User::factory()->create(['impresora_facturacion_id' => $propia->id]);

        $resultado = app(ImpresionService::class)->resolverImpresora(ModuloImpresion::FACTURACION, $usuario);

        $this->assertSame($propia->id, $resultado?->id);
    }

    public function test_resolver_impresora_ignora_la_del_usuario_si_esta_inactiva_y_cae_a_la_predeterminada(): void
    {
        $predeterminada = Impresora::create([
            'nombre' => 'Predeterminada',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        $inactiva = Impresora::create([
            'nombre' => 'Inactiva',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'activa' => false,
        ]);

        $usuario = User::factory()->create(['impresora_facturacion_id' => $inactiva->id]);

        $resultado = app(ImpresionService::class)->resolverImpresora(ModuloImpresion::FACTURACION, $usuario);

        $this->assertSame($predeterminada->id, $resultado?->id);
    }

    public function test_imprimir_ticket_sin_impresora_devuelve_modo_navegador_con_url(): void
    {
        $venta = $this->crearVenta();

        $resultado = app(ImpresionService::class)->imprimirTicket($venta, null);

        $this->assertSame('navegador', $resultado['modo']);
        $this->assertTrue($resultado['exito']);
        $this->assertNull($resultado['error']);
        $this->assertStringContainsString("/ventas/{$venta->id}/ticket", $resultado['url']);
        $this->assertStringContainsString('ancho=80', $resultado['url']);
    }

    public function test_imprimir_ticket_navegador_respeta_el_ancho_de_la_impresora(): void
    {
        $venta = $this->crearVenta();

        $impresora = Impresora::create([
            'nombre' => 'Mostrador',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'ancho_papel' => AnchoPapel::MM58,
        ]);

        $resultado = app(ImpresionService::class)->imprimirTicket($venta, $impresora);

        $this->assertSame('navegador', $resultado['modo']);
        $this->assertStringContainsString('ancho=58', $resultado['url']);
    }

    public function test_imprimir_ticket_red_inalcanzable_falla_sin_lanzar_excepcion_y_ofrece_url_de_respaldo(): void
    {
        $venta = $this->crearVenta();

        $impresora = Impresora::create([
            'nombre' => 'Cocina',
            'tipo_conexion' => TipoConexionImpresora::RED,
            'ip' => '127.0.0.1',
            'puerto' => 1,
            'modulo' => ModuloImpresion::COCINA,
        ]);

        $resultado = app(ImpresionService::class)->imprimirTicket($venta, $impresora);

        $this->assertSame('red', $resultado['modo']);
        $this->assertFalse($resultado['exito']);
        $this->assertNotNull($resultado['error']);
        $this->assertStringContainsString('Cocina', $resultado['error']);
        $this->assertStringContainsString("/ventas/{$venta->id}/ticket", $resultado['url']);
    }
}
