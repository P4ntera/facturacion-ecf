<?php

namespace Tests\Feature;

use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Models\Venta;
use App\Services\ReporteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteServiceReporte608Test extends TestCase
{
    use RefreshDatabase;

    private function crearVenta(array $overrides = []): Venta
    {
        return Venta::create(array_merge([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'ncf' => 'B0200000001',
            'fecha' => now(),
            'subtotal' => '1000.00',
            'descuento' => '0.00',
            'monto_gravado_18' => '1000.00',
            'monto_gravado_16' => '0.00',
            'monto_gravado_0' => '0.00',
            'monto_exento' => '0.00',
            'itbis_18' => '180.00',
            'itbis_16' => '0.00',
            'total_itbis' => '180.00',
            'total' => '1180.00',
            'estado' => EstadoVenta::EMITIDA,
            'tipo_pago' => TipoPago::CONTADO,
        ], $overrides));
    }

    private function crearVentaAnuladaTipoB(array $overrides = []): Venta
    {
        return $this->crearVenta(array_merge([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'estado' => EstadoVenta::ANULADA,
            'motivo_anulacion' => 'Error en factura',
            'anulada_en' => now(),
            'tipo_anulacion_608' => '04',
        ], $overrides));
    }

    public function test_608_incluye_ventas_tipo_b_anuladas_del_mes(): void
    {
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000001']);
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000002']);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(2, $filas);
    }

    public function test_608_excluye_ventas_ecf_anuladas(): void
    {
        // Tipo B → sí va al 608
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000001']);

        // e-CF → NO va al 608 (se anula vía NC E34)
        $this->crearVenta([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'ncf' => 'E320000000001',
            'estado' => EstadoVenta::ANULADA,
            'motivo_anulacion' => 'Error',
            'anulada_en' => now(),
        ]);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
        $this->assertSame('B0200000001', $filas->first()['ncf']);
    }

    public function test_608_excluye_ventas_anuladas_de_otros_meses(): void
    {
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000001', 'anulada_en' => now()]);
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000002', 'anulada_en' => now()->subMonth()]);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
    }

    public function test_608_excluye_ventas_activas(): void
    {
        // Venta activa tipo B → NO va al 608 (no está anulada)
        $this->crearVenta([
            'ncf' => 'B0200000001',
            'estado' => EstadoVenta::EMITIDA,
        ]);

        // Venta anulada tipo B → sí va
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000002']);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
        $this->assertSame('B0200000002', $filas->first()['ncf']);
    }

    public function test_608_usa_fecha_de_anulacion_no_fecha_de_emision(): void
    {
        // Emitida el mes pasado, anulada este mes → SÍ va al 608 de este mes
        $this->crearVentaAnuladaTipoB([
            'ncf' => 'B0200000001',
            'fecha' => now()->subMonth(),
            'anulada_en' => now(),
        ]);

        // Emitida este mes, anulada el mes pasado → NO va al 608 de este mes
        $this->crearVentaAnuladaTipoB([
            'ncf' => 'B0200000002',
            'fecha' => now(),
            'anulada_en' => now()->subMonth(),
        ]);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
        $this->assertSame('B0200000001', $filas->first()['ncf']);
    }

    public function test_608_excluye_ventas_sin_ncf(): void
    {
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000001']);
        $this->crearVentaAnuladaTipoB(['ncf' => null]);
        $this->crearVentaAnuladaTipoB(['ncf' => '']);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
    }

    public function test_formato_txt_tiene_encabezado_correcto(): void
    {
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000001']);

        $servicio = app(ReporteService::class);
        $txt = $servicio->exportar608Txt('130999999', now()->startOfMonth(), now()->endOfMonth());
        $lineas = explode("\n", $txt);

        $encabezado = explode('|', $lineas[0]);
        $this->assertSame('608', $encabezado[0]);
        $this->assertSame('130999999', $encabezado[1]);
        $this->assertSame(now()->format('Ym'), $encabezado[2]);
        $this->assertSame('1', $encabezado[3]);
    }

    public function test_formato_txt_tiene_3_columnas_por_registro(): void
    {
        $this->crearVentaAnuladaTipoB(['ncf' => 'B0200000001']);

        $servicio = app(ReporteService::class);
        $txt = $servicio->exportar608Txt('130999999', now()->startOfMonth(), now()->endOfMonth());
        $lineas = explode("\n", $txt);

        // Línea 0 = encabezado, línea 1 = primer registro
        $columnas = explode('|', $lineas[1]);
        $this->assertCount(3, $columnas);
    }

    public function test_tipo_anulacion_se_incluye_correctamente(): void
    {
        $this->crearVentaAnuladaTipoB([
            'ncf' => 'B0200000001',
            'tipo_anulacion_608' => '06',
        ]);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('06', $filas->first()['tipo_anulacion']);
    }

    public function test_608_vacio_cuando_no_hay_anulaciones(): void
    {
        // Solo ventas activas, ninguna anulada
        $this->crearVenta(['ncf' => 'B0200000001']);

        $filas = app(ReporteService::class)->reporte608(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(0, $filas);

        $txt = app(ReporteService::class)->exportar608Txt('130999999', now()->startOfMonth(), now()->endOfMonth());
        $lineas = explode("\n", $txt);
        $this->assertCount(1, $lineas); // Solo el encabezado
        $this->assertStringStartsWith('608|', $lineas[0]);
    }
}
