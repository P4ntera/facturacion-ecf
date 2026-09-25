<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Exceptions\ArqueoCajaCerradoException;
use App\Exceptions\VentaInvalidaException;
use App\Models\Cliente;
use App\Models\Descuento;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\ArqueoCajaService;
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

    /**
     * VentaService::registrar() no debe emitir una venta con un tipo de comprobante que le
     * pertenece a Compras (41, 43, 47): tipo_comprobante puede venir de una propiedad Livewire
     * pública del POS, así que el service es la última línea de defensa.
     */
    public function test_no_permite_tipo_de_comprobante_de_compra_en_una_venta(): void
    {
        $this->secuencia(TipoComprobante::COMPRAS, 'B41');

        $producto = $this->producto('VS-TIPO-COMPRA');
        $cliente = Cliente::create(['nombre' => 'Cliente Cualquiera', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    /** Nota de Crédito/Débito modifican un e-CF ya emitido: ncf_modifica es obligatorio. */
    public function test_nota_de_credito_requiere_ncf_modifica(): void
    {
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $producto = $this->producto('VS-NC-SIN-REF');
        $cliente = Cliente::create(['nombre' => 'Cliente NC', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('requiere indicar el NCF que modifica');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    /** ncf_modifica es client-controllable: debe referenciar una venta real de esta empresa. */
    public function test_nota_de_credito_rechaza_ncf_modifica_inexistente(): void
    {
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $producto = $this->producto('VS-NC-REF-FALSO');
        $cliente = Cliente::create(['nombre' => 'Cliente NC', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('no existe en una venta de esta empresa');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO->value,
            'ncf_modifica' => 'E320000000999',
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    /** Con un ncf_modifica válido, la Nota de Crédito se registra y lo persiste. */
    public function test_nota_de_credito_persiste_ncf_modifica_valido(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $producto = $this->producto('VS-NC-REF-OK');
        $cliente = Cliente::create(['nombre' => 'Cliente NC', 'activo' => true]);

        $ventaOriginal = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $notaCredito = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO->value,
            'ncf_modifica' => $ventaOriginal->ncf,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertSame($ventaOriginal->ncf, $notaCredito->fresh()->ncf_modifica);
    }

    /** Una venta a precio $0 consume NCF y mueve stock igual que una real: bloqueada por defecto. */
    public function test_no_permite_precio_unitario_cero(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-GRATIS', 0);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('debe ser mayor que cero');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    /** El precio_unitario digitado a mano en $0 también se bloquea, aunque el producto sí tenga precio. */
    public function test_no_permite_precio_unitario_cero_digitado_a_mano(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-GRATIS-MANUAL', 100);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => 0]],
        ], $this->empresaDefault);
    }

    /** Con permite_precio_cero explícito (promos/regalos), la venta a $0 sí se permite. */
    public function test_permite_precio_cero_con_flag_explicito(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-REGALO', 0);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'permite_precio_cero' => true,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertNotNull($venta->id);
        $this->assertEquals('0.00', $venta->total);
    }

    /**
     * VentaService (no solo la UI del POS) sabe calcular un descuento_id (catálogo Descuento,
     * por porcentaje): antes de esta corrección, solo PuntoDeVenta.php precalculaba el monto en
     * pesos, así que llamar al service directamente (una API futura, un import batch) perdía el
     * descuento por completo.
     */
    public function test_descuento_id_calcula_el_monto_desde_el_porcentaje(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-DESC', 100);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);
        $descuento = Descuento::create(['nombre' => '10% empleados', 'porcentaje' => 10, 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'descuento_id' => $descuento->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        // Subtotal 100, 10% de descuento = 10.00, ITBIS 18% sobre 100 = 18.00, total 108.00.
        $this->assertSame('10.00', $venta->descuento);
        $this->assertEqualsWithDelta(108.00, (float) $venta->total, 0.01);
    }

    /** descuento_id es client-controllable: debe pertenecer a esta empresa y estar activo. */
    public function test_descuento_id_de_otra_empresa_o_inactivo_se_rechaza(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-DESC-INACTIVO', 100);
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);
        $descuentoInactivo = Descuento::create(['nombre' => 'Viejo', 'porcentaje' => 20, 'activo' => false]);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'descuento_id' => $descuentoInactivo->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
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
        ], $this->empresaDefault);
    }

    public function test_permite_consumo_igual_o_mayor_al_umbral_con_cliente_con_rnc(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-250K-OK', 300000);
        $cliente = Cliente::create(['nombre' => 'Comercial SRL', 'documento' => '130123456', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

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
        ], $this->empresaDefault);

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
        ], $this->empresaDefault);
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
            ], $this->empresaDefault);
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
        ], $this->empresaDefault);
    }

    public function test_forma_pago_y_arqueo_caja_id_por_defecto_si_se_omiten(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-FP-DEFAULT');
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertSame(FormaPago::EFECTIVO, $venta->forma_pago);
        $this->assertNull($venta->arqueo_caja_id);
    }

    public function test_forma_pago_y_arqueo_caja_id_se_persisten_cuando_se_pasan(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-FP-EXPLICITO');
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);
        $cajero = User::factory()->create();
        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $cajero->id, $this->empresaDefault);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'forma_pago' => FormaPago::TARJETA,
            'arqueo_caja_id' => $arqueo->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertSame(FormaPago::TARJETA, $venta->forma_pago);
        $this->assertSame($arqueo->id, $venta->arqueo_caja_id);
    }

    public function test_no_permite_anular_una_venta_de_un_arqueo_ya_cerrado(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-ARQ-CERRADO');
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);
        $cajero = User::factory()->create();
        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $cajero->id, $this->empresaDefault);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'forma_pago' => FormaPago::EFECTIVO,
            'arqueo_caja_id' => $arqueo->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        app(ArqueoCajaService::class)->cerrar($arqueo, '618.00', null, $cajero->id);

        $this->expectException(ArqueoCajaCerradoException::class);

        app(VentaService::class)->anular($venta, 'Intento tardío', $cajero->id);
    }

    public function test_permite_anular_una_venta_de_un_arqueo_todavia_abierto(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-ARQ-ABIERTO');
        $cliente = Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);
        $cajero = User::factory()->create();
        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $cajero->id, $this->empresaDefault);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'forma_pago' => FormaPago::EFECTIVO,
            'arqueo_caja_id' => $arqueo->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $anulada = app(VentaService::class)->anular($venta, 'Cliente se arrepintió', $cajero->id);

        $this->assertTrue($anulada->estaAnulada());
    }
}
