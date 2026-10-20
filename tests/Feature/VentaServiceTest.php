<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
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

        // Subtotal 100, 10% de descuento = 10.00, ITBIS 18% sobre la base NETA (90) = 16.20,
        // total 106.20. El descuento se prorratea como descuento de línea antes del ITBIS.
        $this->assertSame('100.00', $venta->subtotal);
        $this->assertSame('10.00', $venta->descuento);
        $this->assertSame('90.00', $venta->monto_gravado_18);
        $this->assertSame('16.20', $venta->total_itbis);
        $this->assertSame('106.20', $venta->total);
        $this->assertSame('10.00', $venta->detalles->first()->descuento);
        $this->assertSame('90.00', $venta->detalles->first()->subtotal);
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

        // Anulación simple: el e-CF no llegó a ser válido ante la DGII (sin Nota de Crédito).
        $venta->update(['estado_fiscal' => EstadoFiscal::RECHAZADO]);
        $anulada = app(VentaService::class)->anular($venta, 'Cliente se arrepintió', $cajero->id);

        $this->assertTrue($anulada->estaAnulada());
    }

    /**
     * Cuando el POS envía varias líneas del mismo producto (ej: el usuario escanea el código
     * de barras dos veces), VentaService::procesarLineas() debe consolidarlas en un solo
     * detalle con la suma de cantidades, en vez de crear registros separados que provocan
     * entradas duplicadas en Kardex y problemas en devoluciones parciales.
     */
    public function test_lineas_duplicadas_del_mismo_producto_se_consolidan(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-CONSOLIDA', 200);

        $venta = app(VentaService::class)->registrar([
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 3],
                ['producto_id' => $producto->id, 'cantidad' => 2],
            ],
        ], $this->empresaDefault);

        // Solo debe haber 1 detalle, con cantidad = 5 (3 + 2).
        $this->assertCount(1, $venta->detalles);
        $this->assertEquals('5.000', $venta->detalles->first()->cantidad);
    }

    /**
     * Líneas duplicadas del mismo producto pero con presentación diferente NO deben
     * consolidarse: son ítems distintos (ej: "Coca-Cola Unidad" vs "Coca-Cola Caja").
     */
    public function test_lineas_con_distinta_presentacion_no_se_consolidan(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-NO-CONSOLIDA', 200);

        // Crear dos presentaciones distintas para el mismo producto.
        $unidad = $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Unidad',
            'factor' => 1,
            'precio' => 200,
            'activo' => true,
        ]);
        $caja = $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Caja',
            'factor' => 12,
            'precio' => 2200,
            'activo' => true,
        ]);

        $venta = app(VentaService::class)->registrar([
            'lineas' => [
                ['producto_id' => $producto->id, 'presentacion_id' => $unidad->id, 'cantidad' => 3],
                ['producto_id' => $producto->id, 'presentacion_id' => $caja->id, 'cantidad' => 1],
            ],
        ], $this->empresaDefault);

        // Deben ser 2 detalles distintos: uno por presentación.
        $this->assertCount(2, $venta->detalles);
    }

    /**
     * La consolidación debe sumar también los descuentos por línea cuando se fusionan dos
     * líneas del mismo producto.
     */
    public function test_consolidacion_suma_descuentos_por_linea(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $producto = $this->producto('VS-DESC-CONSOL', 200);

        $venta = app(VentaService::class)->registrar([
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 2, 'descuento' => '10.00'],
                ['producto_id' => $producto->id, 'cantidad' => 1, 'descuento' => '5.00'],
            ],
        ], $this->empresaDefault);

        $detalle = $venta->detalles->first();

        // Cantidad consolidada: 2 + 1 = 3
        $this->assertEquals('3.000', $detalle->cantidad);
        // Descuento consolidado: 10 + 5 = 15
        $this->assertEquals('15.00', $detalle->descuento);
    }
}
