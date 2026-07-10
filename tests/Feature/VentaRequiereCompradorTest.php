<?php

namespace Tests\Feature;

use App\Enums\TipoComprobante;
use App\Models\Venta;
use Tests\TestCase;

class VentaRequiereCompradorTest extends TestCase
{
    private function venta(TipoComprobante $tipo, string $total): Venta
    {
        return new Venta(['tipo_comprobante' => $tipo, 'total' => $total]);
    }

    public function test_tipo_31_siempre_requiere_comprador(): void
    {
        $this->assertTrue($this->venta(TipoComprobante::FACTURA_CREDITO_FISCAL, '10.00')->requiereComprador());
        $this->assertTrue($this->venta(TipoComprobante::FACTURA_CREDITO_FISCAL, '999999.00')->requiereComprador());
    }

    public function test_tipo_32_por_debajo_del_umbral_no_requiere_comprador(): void
    {
        $this->assertFalse($this->venta(TipoComprobante::FACTURA_CONSUMO, '590.00')->requiereComprador());
        $this->assertFalse($this->venta(TipoComprobante::FACTURA_CONSUMO, '249999.99')->requiereComprador());
    }

    public function test_tipo_32_en_o_por_encima_del_umbral_requiere_comprador(): void
    {
        $this->assertTrue($this->venta(TipoComprobante::FACTURA_CONSUMO, '250000.00')->requiereComprador());
        $this->assertTrue($this->venta(TipoComprobante::FACTURA_CONSUMO, '250000.01')->requiereComprador());
        $this->assertTrue($this->venta(TipoComprobante::FACTURA_CONSUMO, '999999.00')->requiereComprador());
    }

    public function test_otros_tipos_de_comprobante_no_requieren_comprador(): void
    {
        $this->assertFalse($this->venta(TipoComprobante::NOTA_DEBITO, '999999.00')->requiereComprador());
        $this->assertFalse($this->venta(TipoComprobante::COMPRAS, '999999.00')->requiereComprador());
    }
}
