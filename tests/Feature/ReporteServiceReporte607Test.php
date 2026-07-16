<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Models\Cliente;
use App\Models\Venta;
use App\Services\ReporteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteServiceReporte607Test extends TestCase
{
    use RefreshDatabase;

    private function crearCliente(TipoDocumentoCliente $tipoDocumento, ?string $documento = null): Cliente
    {
        return Cliente::create([
            'tipo_documento' => $tipoDocumento,
            'documento' => $documento,
            'nombre' => 'Cliente de prueba',
            'activo' => true,
        ]);
    }

    private function crearVenta(array $overrides = []): Venta
    {
        return Venta::create(array_merge([
            'cliente_id' => $this->crearCliente(TipoDocumentoCliente::SIN_DOCUMENTO)->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'ncf' => 'E320000000001',
            'fecha' => now(),
            'subtotal' => '100.00',
            'total_itbis' => '18.00',
            'total' => '118.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ], $overrides));
    }

    public function test_mapea_rnc_como_tipo_identificacion_1(): void
    {
        $cliente = $this->crearCliente(TipoDocumentoCliente::RNC, '130000000');
        $this->crearVenta(['cliente_id' => $cliente->id, 'ncf' => 'E310000000001']);

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
        $this->assertSame('130000000', $filas->first()['rnc_cedula']);
        $this->assertSame(1, $filas->first()['tipo_identificacion']);
    }

    public function test_mapea_cedula_como_tipo_identificacion_2(): void
    {
        $cliente = $this->crearCliente(TipoDocumentoCliente::CEDULA, '00112345678');
        $this->crearVenta(['cliente_id' => $cliente->id]);

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('00112345678', $filas->first()['rnc_cedula']);
        $this->assertSame(2, $filas->first()['tipo_identificacion']);
    }

    public function test_consumidor_sin_documento_deja_identificacion_en_null_no_con_codigo_inventado(): void
    {
        $this->crearVenta();

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth());

        $this->assertNull($filas->first()['rnc_cedula']);
        $this->assertNull($filas->first()['tipo_identificacion']);
    }

    public function test_ventas_anuladas_se_excluyen_por_completo_del_607(): void
    {
        $this->crearVenta(['ncf' => 'E320000000010', 'estado' => EstadoVenta::EMITIDA]);
        $this->crearVenta(['ncf' => 'E320000000011', 'estado' => EstadoVenta::ANULADA]);

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
        $this->assertSame('E320000000010', $filas->first()['numero_comprobante']);
    }

    public function test_ventas_sin_ncf_se_excluyen(): void
    {
        $this->crearVenta(['ncf' => null]);

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(0, $filas);
    }

    public function test_montos_y_tipo_ingreso_por_defecto(): void
    {
        $this->crearVenta([
            'ncf' => 'E340000000001',
            'ncf_modifica' => 'E310000000002',
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO,
            'subtotal' => '500.00',
            'total_itbis' => '90.00',
            'total' => '590.00',
        ]);

        $fila = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth())->first();

        $this->assertSame('E310000000002', $fila['numero_comprobante_modificado']);
        $this->assertSame(ReporteService::TIPO_INGRESO_DEFECTO, $fila['tipo_ingreso']);
        $this->assertSame('500.00', $fila['monto_facturado']);
        $this->assertSame('90.00', $fila['itbis_facturado']);
        $this->assertSame('590.00', $fila['monto_total']);
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->crearVenta(['ncf' => 'E320000000020', 'fecha' => now()->subMonth()]);

        $filas = app(ReporteService::class)->reporte607(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(0, $filas);
    }
}
