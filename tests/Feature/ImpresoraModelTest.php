<?php

namespace Tests\Feature;

use App\Enums\ModuloImpresion;
use App\Enums\TipoConexionImpresora;
use App\Exceptions\ImpresoraInvalidaException;
use App\Models\Impresora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpresoraModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_impresora_navegador_anula_ip_y_puerto_aunque_se_envien(): void
    {
        $impresora = Impresora::create([
            'nombre' => 'Mostrador',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'ip' => '10.0.0.1',
            'puerto' => 1234,
            'modulo' => ModuloImpresion::FACTURACION,
        ]);

        $this->assertNull($impresora->ip);
        $this->assertNull($impresora->puerto);
    }

    public function test_una_impresora_red_requiere_ip_valida(): void
    {
        $this->expectException(ImpresoraInvalidaException::class);

        Impresora::create([
            'nombre' => 'Cocina',
            'tipo_conexion' => TipoConexionImpresora::RED,
            'ip' => 'no-es-ip',
            'puerto' => 9100,
            'modulo' => ModuloImpresion::COCINA,
        ]);
    }

    public function test_una_impresora_red_requiere_puerto(): void
    {
        $this->expectException(ImpresoraInvalidaException::class);

        Impresora::create([
            'nombre' => 'Cocina',
            'tipo_conexion' => TipoConexionImpresora::RED,
            'ip' => '192.168.1.50',
            'puerto' => null,
            'modulo' => ModuloImpresion::COCINA,
        ]);
    }

    public function test_marcar_predeterminada_desmarca_las_demas_del_mismo_modulo(): void
    {
        $primera = Impresora::create([
            'nombre' => 'Primera',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        $segunda = Impresora::create([
            'nombre' => 'Segunda',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        $this->assertFalse($primera->fresh()->predeterminada);
        $this->assertTrue($segunda->fresh()->predeterminada);
    }

    public function test_predeterminada_no_afecta_otros_modulos(): void
    {
        $facturacion = Impresora::create([
            'nombre' => 'Facturación',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        $reportes = Impresora::create([
            'nombre' => 'Reportes',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::REPORTES,
            'predeterminada' => true,
        ]);

        $this->assertTrue($facturacion->fresh()->predeterminada);
        $this->assertTrue($reportes->fresh()->predeterminada);
    }

    public function test_scopes_activas_y_por_modulo(): void
    {
        Impresora::create(['nombre' => 'A', 'tipo_conexion' => TipoConexionImpresora::NAVEGADOR, 'modulo' => ModuloImpresion::FACTURACION, 'activa' => true]);
        Impresora::create(['nombre' => 'B', 'tipo_conexion' => TipoConexionImpresora::NAVEGADOR, 'modulo' => ModuloImpresion::FACTURACION, 'activa' => false]);
        Impresora::create(['nombre' => 'C', 'tipo_conexion' => TipoConexionImpresora::NAVEGADOR, 'modulo' => ModuloImpresion::REPORTES, 'activa' => true]);

        $this->assertSame(2, Impresora::activas()->count());
        $this->assertSame(2, Impresora::porModulo(ModuloImpresion::FACTURACION)->count());
        $this->assertSame(1, Impresora::activas()->porModulo(ModuloImpresion::FACTURACION)->count());
    }

    public function test_es_de_red(): void
    {
        $red = Impresora::create([
            'nombre' => 'Cocina',
            'tipo_conexion' => TipoConexionImpresora::RED,
            'ip' => '192.168.1.50',
            'puerto' => 9100,
            'modulo' => ModuloImpresion::COCINA,
        ]);

        $navegador = Impresora::create([
            'nombre' => 'Mostrador',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
        ]);

        $this->assertTrue($red->esDeRed());
        $this->assertFalse($navegador->esDeRed());
    }
}
