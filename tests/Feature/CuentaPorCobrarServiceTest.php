<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\CuentaConPagosRegistradosException;
use App\Exceptions\PagoExcedeSaldoException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\CuentaPorCobrarService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CuentaPorCobrarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ProductoObserver notifica stock bajo (inventario.ajustar) cuando anular una venta/compra
        // devuelve el stock a 0; sin el catálogo sembrado, ese permiso no existe todavía.
        $this->seed(RolePermissionSeeder::class);
    }

    private function secuencia(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function producto(string $codigo = 'CXC-P1'): Producto
    {
        return Producto::create([
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    /** Vende 1 unidad de RD$100 (18% ITBIS -> total 118.00) a crédito. */
    private function venderACredito(?string $fechaLimitePago = null): \App\Models\Venta
    {
        $this->secuencia();
        $cliente = Cliente::create(['nombre' => 'Cliente CxC', 'activo' => true]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_pago' => TipoPago::CREDITO->value,
            'fecha_limite_pago' => $fechaLimitePago,
            'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    public function test_venta_a_credito_crea_cuenta_por_cobrar(): void
    {
        $venta = $this->venderACredito();

        $this->assertNotNull($venta->cuentaPorCobrar);
        $this->assertEqualsWithDelta(118.00, (float) $venta->cuentaPorCobrar->monto_total, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $venta->cuentaPorCobrar->monto_pagado, 0.01);
    }

    public function test_venta_a_contado_no_crea_cuenta_por_cobrar(): void
    {
        $this->secuencia();
        $cliente = Cliente::create(['nombre' => 'Cliente Contado', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $this->producto('CXC-CONTADO')->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertNull($venta->cuentaPorCobrar);
    }

    public function test_fecha_limite_pago_por_defecto_es_30_dias(): void
    {
        $venta = $this->venderACredito();

        $this->assertTrue(
            $venta->cuentaPorCobrar->fecha_vencimiento->isSameDay(now()->addDays(30))
        );
    }

    public function test_registrar_pago_parcial_deja_estado_parcial(): void
    {
        $venta = $this->venderACredito();
        $cuenta = $venta->cuentaPorCobrar;

        app(CuentaPorCobrarService::class)->registrarPago($cuenta, [
            'monto' => 50,
            'fecha' => now(),
            'forma_pago' => FormaPago::EFECTIVO->value,
        ], User::factory()->create()->id);

        $cuenta->refresh();
        $this->assertEqualsWithDelta(50.00, (float) $cuenta->monto_pagado, 0.01);
        $this->assertEqualsWithDelta(68.00, $cuenta->montoPendiente(), 0.01);
        $this->assertSame('parcial', $cuenta->estado()->value);
    }

    public function test_registrar_pago_completo_marca_pagada(): void
    {
        $venta = $this->venderACredito();
        $cuenta = $venta->cuentaPorCobrar;

        app(CuentaPorCobrarService::class)->registrarPago($cuenta, [
            'monto' => 118,
            'fecha' => now(),
            'forma_pago' => FormaPago::TRANSFERENCIA->value,
            'referencia' => 'Ref-1',
        ], User::factory()->create()->id);

        $this->assertSame('pagada', $cuenta->refresh()->estado()->value);
        $this->assertEqualsWithDelta(0.00, $cuenta->montoPendiente(), 0.01);
    }

    public function test_no_permite_pago_que_exceda_el_pendiente(): void
    {
        $venta = $this->venderACredito();

        $this->expectException(PagoExcedeSaldoException::class);

        app(CuentaPorCobrarService::class)->registrarPago($venta->cuentaPorCobrar, [
            'monto' => 200,
            'fecha' => now(),
            'forma_pago' => FormaPago::EFECTIVO->value,
        ], User::factory()->create()->id);
    }

    public function test_estado_vencida_cuando_pasa_la_fecha_sin_pagar(): void
    {
        $venta = $this->venderACredito();
        $venta->cuentaPorCobrar->update(['fecha_vencimiento' => now()->subDays(5)]);

        $cuenta = $venta->cuentaPorCobrar->fresh();

        $this->assertSame('vencida', $cuenta->estado()->value);
        $this->assertSame(5, $cuenta->diasVencido());
    }

    public function test_anular_pago_revierte_monto_pagado(): void
    {
        $venta = $this->venderACredito();
        $cuenta = $venta->cuentaPorCobrar;

        $pago = app(CuentaPorCobrarService::class)->registrarPago($cuenta, [
            'monto' => 50,
            'fecha' => now(),
            'forma_pago' => FormaPago::EFECTIVO->value,
        ], User::factory()->create()->id);

        app(CuentaPorCobrarService::class)->anularPago($pago, 'Registrado por error');

        $this->assertEqualsWithDelta(0.00, (float) $cuenta->refresh()->monto_pagado, 0.01);
        $this->assertTrue($pago->refresh()->estaAnulado());
    }

    public function test_no_permite_anular_un_pago_dos_veces(): void
    {
        $venta = $this->venderACredito();

        $pago = app(CuentaPorCobrarService::class)->registrarPago($venta->cuentaPorCobrar, [
            'monto' => 50,
            'fecha' => now(),
            'forma_pago' => FormaPago::EFECTIVO->value,
        ], User::factory()->create()->id);

        app(CuentaPorCobrarService::class)->anularPago($pago, 'Motivo');

        $this->expectException(RuntimeException::class);

        app(CuentaPorCobrarService::class)->anularPago($pago->fresh(), 'Motivo');
    }

    public function test_no_permite_anular_venta_con_pagos_registrados(): void
    {
        $venta = $this->venderACredito();

        app(CuentaPorCobrarService::class)->registrarPago($venta->cuentaPorCobrar, [
            'monto' => 50,
            'fecha' => now(),
            'forma_pago' => FormaPago::EFECTIVO->value,
        ], User::factory()->create()->id);

        $this->expectException(CuentaConPagosRegistradosException::class);

        app(VentaService::class)->anular($venta, 'Cliente se arrepintió', User::factory()->create()->id);
    }

    public function test_anular_venta_sin_pagos_elimina_la_cuenta_por_cobrar(): void
    {
        $venta = $this->venderACredito();

        app(VentaService::class)->anular($venta, 'Error de digitación', User::factory()->create()->id);

        $this->assertNull($venta->fresh()->cuentaPorCobrar);
    }
}
