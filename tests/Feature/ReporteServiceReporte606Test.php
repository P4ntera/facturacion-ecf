<?php

namespace Tests\Feature;

use App\Enums\EstadoCompra;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Services\ReporteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteServiceReporte606Test extends TestCase
{
    use RefreshDatabase;

    private function crearProveedor(array $overrides = []): Proveedor
    {
        return Proveedor::create(array_merge([
            // RNC único por proveedor: (empresa_id, rnc) es unique, y varios tests crean más de
            // una compra (cada una con su proveedor).
            'rnc' => fake()->unique()->numerify('1########'),
            'nombre' => 'Proveedor de prueba',
            'activo' => true,
        ], $overrides));
    }

    private function crearCompra(array $overrides = []): Compra
    {
        $proveedor = $overrides['proveedor_id'] ?? $this->crearProveedor()->id;
        unset($overrides['proveedor_id']);

        return Compra::create(array_merge([
            'proveedor_id' => $proveedor,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'ncf' => 'B0100000001',
            'fecha' => now(),
            'subtotal' => '1000.00',
            'itbis' => '180.00',
            'total' => '1180.00',
            'estado' => EstadoCompra::REGISTRADA,
            'tipo_pago' => TipoPago::CONTADO,
            'tipo_bienes_servicios_606' => '09',
            'forma_pago_606' => '01',
        ], $overrides));
    }

    public function test_606_incluye_compras_del_mes_con_ncf(): void
    {
        $this->crearCompra(['ncf' => 'B0100000001']);
        $this->crearCompra(['ncf' => 'B0100000002']);

        $filas = app(ReporteService::class)->reporte606(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(2, $filas);
    }

    public function test_606_excluye_compras_sin_ncf(): void
    {
        $this->crearCompra(['ncf' => 'B0100000001']);
        $this->crearCompra(['ncf' => null]);           // Sin NCF
        $this->crearCompra(['ncf' => '']);              // NCF vacío

        $filas = app(ReporteService::class)->reporte606(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
    }

    public function test_606_excluye_compras_anuladas(): void
    {
        $this->crearCompra(['ncf' => 'B0100000001', 'estado' => EstadoCompra::REGISTRADA]);
        $this->crearCompra(['ncf' => 'B0100000002', 'estado' => EstadoCompra::ANULADA]);

        $filas = app(ReporteService::class)->reporte606(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
        $this->assertSame('B0100000001', $filas->first()['ncf']);
    }

    public function test_606_excluye_compras_de_otros_meses(): void
    {
        $this->crearCompra(['ncf' => 'B0100000001', 'fecha' => now()]);
        $this->crearCompra(['ncf' => 'B0100000002', 'fecha' => now()->subMonth()]);

        $filas = app(ReporteService::class)->reporte606(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(1, $filas);
    }

    public function test_tipo_identificacion_rnc_9_digitos_es_1(): void
    {
        $servicio = app(ReporteService::class);

        $this->assertSame(1, $servicio->tipoIdentificacion606('130000001'));
    }

    public function test_tipo_identificacion_cedula_11_digitos_es_2(): void
    {
        $servicio = app(ReporteService::class);

        $this->assertSame(2, $servicio->tipoIdentificacion606('00112345678'));
    }

    public function test_tipo_identificacion_null_para_rnc_vacio(): void
    {
        $servicio = app(ReporteService::class);

        $this->assertNull($servicio->tipoIdentificacion606(null));
        $this->assertNull($servicio->tipoIdentificacion606(''));
    }

    public function test_formato_txt_tiene_encabezado_correcto(): void
    {
        $this->crearCompra(['ncf' => 'B0100000001']);

        $servicio = app(ReporteService::class);
        $txt = $servicio->exportar606Txt('130999999', now()->startOfMonth(), now()->endOfMonth());
        $lineas = explode("\n", $txt);

        $encabezado = explode('|', $lineas[0]);
        $this->assertSame('606', $encabezado[0]);
        $this->assertSame('130999999', $encabezado[1]);
        $this->assertSame(now()->format('Ym'), $encabezado[2]);
        $this->assertSame('1', $encabezado[3]);
    }

    public function test_formato_txt_tiene_23_columnas_por_registro(): void
    {
        $this->crearCompra(['ncf' => 'B0100000001']);

        $servicio = app(ReporteService::class);
        $txt = $servicio->exportar606Txt('130999999', now()->startOfMonth(), now()->endOfMonth());
        $lineas = explode("\n", $txt);

        // Línea 0 = encabezado, línea 1 = primer registro
        $columnas = explode('|', $lineas[1]);
        $this->assertCount(23, $columnas);
    }

    public function test_montos_cuadran_subtotal_mas_itbis_igual_total(): void
    {
        $this->crearCompra([
            'ncf' => 'B0100000001',
            'subtotal' => '5000.00',
            'itbis' => '900.00',
            'total' => '5900.00',
        ]);

        $filas = app(ReporteService::class)->reporte606(now()->startOfMonth(), now()->endOfMonth());
        $fila = $filas->first();

        $this->assertSame('5000.00', $fila['monto_bienes']);
        $this->assertSame('900.00', $fila['itbis_facturado']);
        $this->assertSame('5900.00', $fila['total_facturado']);
    }

    public function test_forma_de_pago_se_incluye_correctamente(): void
    {
        $this->crearCompra(['ncf' => 'B0100000001', 'forma_pago_606' => '04']);

        $filas = app(ReporteService::class)->reporte606(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('04', $filas->first()['forma_pago']);
    }
}
