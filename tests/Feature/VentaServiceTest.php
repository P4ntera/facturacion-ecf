<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Exceptions\VentaInvalidaException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function secuencia(TipoComprobante $tipo, string $prefijo): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => $tipo,
            'prefijo' => $prefijo,
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function producto(string $codigo, float $precio = 100): Producto
    {
        return Producto::create([
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => $precio,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 1,
            'activo' => true,
        ]);
    }

    public function test_bloquea_consumo_igual_o_mayor_al_umbral_sin_cliente_con_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-250K', 300000);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('Para facturas de consumo de RD$250,000 o más, el cliente con RNC/Cédula es obligatorio.');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);
    }

    public function test_permite_consumo_igual_o_mayor_al_umbral_con_cliente_con_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-250K-OK', 300000);
        $cliente = Cliente::create(['nombre' => 'Comercial SRL', 'documento' => '130123456', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        $this->assertNotNull($venta->id);
    }

    public function test_permite_consumo_por_debajo_del_umbral_con_consumidor_final(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-BAJO');
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        $this->assertNotNull($venta->id);
    }

    public function test_bloquea_credito_fiscal_sin_cliente_con_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');

        $producto = $this->producto('VS-31');
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('La Factura de Crédito Fiscal (e-CF 31) requiere un cliente con RNC/Cédula.');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);
    }

    public function test_no_consume_el_ncf_cuando_bloquea_por_falta_de_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-NCF', 300000);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        try {
            app(VentaService::class)->registrar([
                'cliente_id' => $cliente->id,
                'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ]);
        } catch (VentaInvalidaException) {
            // esperado
        }

        $this->assertSame(1, SecuenciaNcf::where('prefijo', 'E32')->first()->secuencia_actual);
    }

    public function test_bloquea_credito_fiscal_sin_cliente_con_rnc_aunque_sea_bajo_monto(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');

        $producto = $this->producto('VS-31-BAJO', 10);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);
    }
}
