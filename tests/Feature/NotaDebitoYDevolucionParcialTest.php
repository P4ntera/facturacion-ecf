<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Exceptions\VentaInvalidaException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Services\Dgii\EcfBuilder;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaDebitoYDevolucionParcialTest extends TestCase
{
    use RefreshDatabase;

    private function secuencia(TipoComprobante $tipo, string $prefijo): void
    {
        SecuenciaNcf::create([
            'empresa_id' => $this->empresaDefault->id,
            'tipo_comprobante' => $tipo,
            'prefijo' => $prefijo,
            'desde' => 1,
            'hasta' => 999,
            'siguiente' => 1,
            'activa' => true,
            'vencimiento' => now()->addYear(),
        ]);
    }

    private function producto(string $codigo, TasaItbis $tasa = TasaItbis::DIECIOCHO): Producto
    {
        return Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'precio' => '100.00',
            'costo' => '50.00',
            'stock' => 100,
            'tasa_itbis' => $tasa,
            'activo' => true,
        ]);
    }

    private function ventaAceptada(?Cliente $cliente = null): \App\Models\Venta
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente?->id,
            'lineas' => [
                ['producto_id' => $this->producto('P1')->id, 'cantidad' => 5],
                ['producto_id' => $this->producto('P2', TasaItbis::CERO)->id, 'cantidad' => 3],
            ],
        ], $this->empresaDefault);

        $venta->update(['estado_fiscal' => EstadoFiscal::ACEPTADO]);

        return $venta->refresh();
    }

    // ===================== NOTA DE DÉBITO (E33) =====================

    public function test_puede_emitir_nota_de_debito_sobre_venta_aceptada(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_DEBITO, 'E33');

        $nd = app(VentaService::class)->emitirNotaDebito(
            $venta,
            $this->empresaDefault,
            [['producto_id' => $venta->detalles->first()->producto_id, 'cantidad' => 1, 'monto' => '50.00']],
            'Ajuste de precio',
        );

        $this->assertSame(TipoComprobante::NOTA_DEBITO, $nd->tipo_comprobante);
        $this->assertStringStartsWith('E33', $nd->ncf);
        $this->assertSame($venta->ncf, $nd->ncf_modifica);
        $this->assertSame($venta->id, $nd->venta_modificada_id);
        $this->assertSame(EstadoFiscal::PENDIENTE, $nd->estado_fiscal);
        $this->assertSame(EstadoVenta::EMITIDA, $nd->estado);
        $this->assertCount(1, $nd->detalles);
        $this->assertTrue(bccomp($nd->total, '0', 2) > 0);
    }

    public function test_nota_debito_no_permite_venta_no_electronica(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');
        $producto = $this->producto('PB');

        $venta = app(VentaService::class)->registrar([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->emitirNotaDebito(
            $venta, $this->empresaDefault,
            [['producto_id' => $producto->id, 'cantidad' => 1, 'monto' => '10.00']],
            'Motivo',
        );
    }

    public function test_nota_debito_no_permite_venta_anulada(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        app(VentaService::class)->anular($venta, 'Test anulación');

        $this->secuencia(TipoComprobante::NOTA_DEBITO, 'E33');

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->emitirNotaDebito(
            $venta->refresh(), $this->empresaDefault,
            [['producto_id' => $venta->detalles->first()->producto_id, 'cantidad' => 1, 'monto' => '10.00']],
            'Motivo',
        );
    }

    public function test_nota_debito_no_permite_venta_pendiente(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $producto = $this->producto('PP');

        $venta = app(VentaService::class)->registrar([
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertSame(EstadoFiscal::PENDIENTE, $venta->estado_fiscal);

        $this->secuencia(TipoComprobante::NOTA_DEBITO, 'E33');

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->emitirNotaDebito(
            $venta, $this->empresaDefault,
            [['producto_id' => $producto->id, 'cantidad' => 1, 'monto' => '10.00']],
            'Motivo',
        );
    }

    public function test_nota_debito_no_permite_montos_cero_o_negativos(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_DEBITO, 'E33');

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('positivos');

        app(VentaService::class)->emitirNotaDebito(
            $venta, $this->empresaDefault,
            [['producto_id' => $venta->detalles->first()->producto_id, 'cantidad' => 1, 'monto' => '0']],
            'Motivo',
        );
    }

    public function test_nota_debito_requiere_motivo(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_DEBITO, 'E33');

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('motivo');

        app(VentaService::class)->emitirNotaDebito(
            $venta, $this->empresaDefault,
            [['producto_id' => $venta->detalles->first()->producto_id, 'cantidad' => 1, 'monto' => '10.00']],
            '',
        );
    }

    public function test_ecf_nota_debito_tiene_informacion_referencia_correcta(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_DEBITO, 'E33');

        $nd = app(VentaService::class)->emitirNotaDebito(
            $venta, $this->empresaDefault,
            [['producto_id' => $venta->detalles->first()->producto_id, 'cantidad' => 1, 'monto' => '50.00']],
            'Ajuste de precio',
        )->refresh();

        $ecf = app(EcfBuilder::class)->construir($nd)['ECF'];

        $this->assertSame('33', $ecf['Encabezado']['IdDoc']['TipoeCF']);
        $this->assertSame($venta->ncf, $ecf['InformacionReferencia']['NCFModificado']);
        $this->assertSame('3', $ecf['InformacionReferencia']['CodigoModificacion']);
    }

    // ===================== NOTA DE CRÉDITO PARCIAL =====================

    public function test_puede_emitir_nc_parcial_devolviendo_algunos_productos(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $detalleOriginal = $venta->detalles->first();
        $stockAntes = $detalleOriginal->producto->refresh()->stock;

        $nc = app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $detalleOriginal->producto_id, 'cantidad' => 2]],
            'Devolución parcial',
        );

        $this->assertSame(TipoComprobante::NOTA_CREDITO, $nc->tipo_comprobante);
        $this->assertStringStartsWith('E34', $nc->ncf);
        $this->assertSame($venta->ncf, $nc->ncf_modifica);
        $this->assertSame($venta->id, $nc->venta_modificada_id);
        $this->assertCount(1, $nc->detalles);
        $this->assertSame(EstadoVenta::EMITIDA, $nc->estado);

        $stockDespues = $detalleOriginal->producto->refresh()->stock;
        $this->assertEquals($stockAntes + 2, $stockDespues);
    }

    public function test_nc_parcial_no_puede_devolver_mas_de_lo_vendido(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $detalleOriginal = $venta->detalles->first();

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('Disponible');

        app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $detalleOriginal->producto_id, 'cantidad' => 999]],
            'Motivo',
        );
    }

    public function test_nc_parcial_descuenta_devoluciones_previas(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $detalleOriginal = $venta->detalles->first();

        app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $detalleOriginal->producto_id, 'cantidad' => 3]],
            'Primera devolución',
        );

        app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $detalleOriginal->producto_id, 'cantidad' => 2]],
            'Segunda devolución',
        );

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $detalleOriginal->producto_id, 'cantidad' => 1]],
            'Tercera devolución — debería fallar (5 vendidos, 5 ya devueltos)',
        );
    }

    public function test_nc_parcial_usa_precio_original_no_actual(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $detalleOriginal = $venta->detalles->first();
        $precioOriginal = $detalleOriginal->precio_unitario;

        $detalleOriginal->producto->update(['precio' => '999.99']);

        $nc = app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $detalleOriginal->producto_id, 'cantidad' => 1]],
            'Devolución',
        );

        $this->assertSame(
            (string) $precioOriginal,
            (string) $nc->detalles->first()->precio_unitario,
        );
    }

    public function test_nc_parcial_no_permite_venta_no_electronica(): void
    {
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');
        $producto = $this->producto('PB');

        $venta = app(VentaService::class)->registrar([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->expectException(VentaInvalidaException::class);

        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $producto->id, 'cantidad' => 1]],
            'Motivo',
        );
    }

    public function test_nc_parcial_no_permite_venta_anulada(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        app(VentaService::class)->anular($venta, 'Test');

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->emitirNotaCreditoParcial(
            $venta->refresh(), $this->empresaDefault,
            [['producto_id' => $venta->detalles->first()->producto_id, 'cantidad' => 1]],
            'Motivo',
        );
    }

    public function test_ecf_nc_parcial_tiene_informacion_referencia_con_codigo_5(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        $nc = app(VentaService::class)->emitirNotaCreditoParcial(
            $venta, $this->empresaDefault,
            [['producto_id' => $venta->detalles->first()->producto_id, 'cantidad' => 1]],
            'Devolución parcial',
        )->refresh();

        $ecf = app(EcfBuilder::class)->construir($nc)['ECF'];

        $this->assertSame('34', $ecf['Encabezado']['IdDoc']['TipoeCF']);
        $this->assertSame($venta->ncf, $ecf['InformacionReferencia']['NCFModificado']);
        $this->assertSame('5', $ecf['InformacionReferencia']['CodigoModificacion']);
    }

    public function test_ecf_anulacion_total_sigue_usando_codigo_1(): void
    {
        $venta = $this->ventaAceptada();
        $this->secuencia(TipoComprobante::NOTA_CREDITO, 'E34');

        app(VentaService::class)->anular($venta, 'Anulación total');

        $ncAnulacion = $venta->notasCredito()->first();
        $ecf = app(EcfBuilder::class)->construir($ncAnulacion)['ECF'];

        $this->assertSame('1', $ecf['InformacionReferencia']['CodigoModificacion']);
    }
}
