<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Exceptions\CuentaConPagosRegistradosException;
use App\Exceptions\PagoExcedeSaldoException;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CompraService;
use App\Services\CuentaPorPagarService;
use App\Services\DevolucionCompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CuentaPorPagarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ProductoObserver notifica stock bajo (inventario.ajustar) cuando anular una compra
        // devuelve el stock a 0; sin el catálogo sembrado, ese permiso no existe todavía.
        $this->seed(RolePermissionSeeder::class);
    }

    private function producto(string $codigo = 'CXP-P1'): Producto
    {
        return Producto::create([
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    /** Compra 10 unidades a RD$100 (18% ITBIS -> total 1180.00) a crédito. */
    private function comprarACredito(?string $fechaVencimiento = null): Compra
    {
        $proveedor = Proveedor::factory()->create();
        $user = User::factory()->create();

        return app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'tipo_pago' => TipoPago::CREDITO->value,
            'fecha_vencimiento' => $fechaVencimiento,
            'lineas' => [
                ['producto_id' => $this->producto()->id, 'cantidad' => 10, 'costo_unitario' => 100],
            ],
        ], $user->id);
    }

    public function test_compra_a_credito_crea_cuenta_por_pagar(): void
    {
        $compra = $this->comprarACredito();

        $this->assertNotNull($compra->cuentaPorPagar);
        $this->assertEqualsWithDelta(1180.00, (float) $compra->cuentaPorPagar->monto_total, 0.01);
    }

    public function test_compra_a_contado_no_crea_cuenta_por_pagar(): void
    {
        $proveedor = Proveedor::factory()->create();
        $user = User::factory()->create();

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $this->producto('CXP-CONTADO')->id, 'cantidad' => 1, 'costo_unitario' => 100],
            ],
        ], $user->id);

        $this->assertNull($compra->cuentaPorPagar);
    }

    public function test_registrar_pago_parcial_y_completo(): void
    {
        $compra = $this->comprarACredito();
        $cuenta = $compra->cuentaPorPagar;
        $user = User::factory()->create();

        app(CuentaPorPagarService::class)->registrarPago($cuenta, [
            'monto' => 680,
            'fecha' => now(),
            'forma_pago' => FormaPago::TRANSFERENCIA->value,
        ], $user->id);

        $this->assertSame('parcial', $cuenta->refresh()->estado()->value);

        app(CuentaPorPagarService::class)->registrarPago($cuenta, [
            'monto' => 500,
            'fecha' => now(),
            'forma_pago' => FormaPago::TRANSFERENCIA->value,
        ], $user->id);

        $this->assertSame('pagada', $cuenta->refresh()->estado()->value);
    }

    public function test_no_permite_pago_que_exceda_el_pendiente(): void
    {
        $compra = $this->comprarACredito();

        $this->expectException(PagoExcedeSaldoException::class);

        app(CuentaPorPagarService::class)->registrarPago($compra->cuentaPorPagar, [
            'monto' => 5000,
            'fecha' => now(),
            'forma_pago' => FormaPago::TRANSFERENCIA->value,
        ], User::factory()->create()->id);
    }

    public function test_no_permite_anular_compra_con_pagos_registrados(): void
    {
        $compra = $this->comprarACredito();

        app(CuentaPorPagarService::class)->registrarPago($compra->cuentaPorPagar, [
            'monto' => 500,
            'fecha' => now(),
            'forma_pago' => FormaPago::TRANSFERENCIA->value,
        ], User::factory()->create()->id);

        $this->expectException(CuentaConPagosRegistradosException::class);

        app(CompraService::class)->anular($compra, 'Error', User::factory()->create()->id);
    }

    public function test_anular_compra_sin_pagos_elimina_la_cuenta_por_pagar(): void
    {
        $compra = $this->comprarACredito();

        app(CompraService::class)->anular($compra, 'Error de digitación', User::factory()->create()->id);

        $this->assertNull($compra->fresh()->cuentaPorPagar);
    }

    public function test_devolucion_reduce_el_monto_total_de_la_cuenta_por_pagar(): void
    {
        $compra = $this->comprarACredito();
        $detalle = $compra->detalles()->first();
        $user = User::factory()->create();

        // Devuelve 2 de 10 unidades: subtotal 200, itbis 36, total 236.
        app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha' => now(),
            'motivo' => 'Faltante en la entrega',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 2],
            ],
        ], $user->id);

        $this->assertEqualsWithDelta(944.00, (float) $compra->cuentaPorPagar->fresh()->monto_total, 0.01);
    }

    public function test_anular_devolucion_restaura_el_monto_de_la_cuenta_por_pagar(): void
    {
        $compra = $this->comprarACredito();
        $detalle = $compra->detalles()->first();
        $user = User::factory()->create();

        $devolucion = app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha' => now(),
            'motivo' => 'Faltante en la entrega',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 2],
            ],
        ], $user->id);

        app(DevolucionCompraService::class)->anular($devolucion, 'Se recuperó la mercancía', $user->id);

        $this->assertEqualsWithDelta(1180.00, (float) $compra->cuentaPorPagar->fresh()->monto_total, 0.01);
    }

    public function test_no_permite_devolucion_que_deje_la_cuenta_por_debajo_de_lo_ya_pagado(): void
    {
        $compra = $this->comprarACredito();
        $detalle = $compra->detalles()->first();
        $user = User::factory()->create();

        // Paga 1000 de 1180: si se devuelven 2 unidades (236), el nuevo total (944) quedaría
        // por debajo de lo ya pagado (1000) -> debe bloquearse.
        app(CuentaPorPagarService::class)->registrarPago($compra->cuentaPorPagar, [
            'monto' => 1000,
            'fecha' => now(),
            'forma_pago' => FormaPago::TRANSFERENCIA->value,
        ], $user->id);

        $this->expectException(RuntimeException::class);

        app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha' => now(),
            'motivo' => 'Faltante',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 2],
            ],
        ], $user->id);
    }
}
