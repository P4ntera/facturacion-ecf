<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CompraService;
use App\Services\DevolucionCompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DevolucionCompraServiceTest extends TestCase
{
    use RefreshDatabase;

    private function crearProducto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo'         => 'P-' . fake()->unique()->numerify('###'),
            'nombre'         => 'Producto Test',
            'tipo'           => TipoProducto::PRODUCTO->value,
            'costo'          => 50,
            'precio'         => 100,
            'tasa_itbis'     => TasaItbis::DIECIOCHO->value,
            'controla_stock' => true,
            'stock'          => 0,
            'stock_minimo'   => 0,
            'activo'         => true,
        ], $overrides));
    }

    /** Registra una compra de 10 unidades a RD$100 (18% ITBIS) y devuelve [compra, detalle, producto]. */
    private function crearCompraDe10Unidades(): array
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();

        $compra = app(CompraService::class)->crear([
            'proveedor_id'     => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf'              => null,
            'fecha'            => now(),
            'itbis_incluido'   => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 10, 'costo_unitario' => 100],
            ],
        ], $user->id);

        return [$compra, $compra->detalles()->first(), $producto, $user];
    }

    public function test_devolver_parte_de_una_linea_reduce_stock_y_calcula_totales(): void
    {
        [$compra, $detalle, $producto, $user] = $this->crearCompraDe10Unidades();

        $this->assertEquals(10, (float) $producto->fresh()->stock);

        $devolucion = app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha'     => now(),
            'motivo'    => 'Faltante en la entrega',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 2],
            ],
        ], $user->id);

        $this->assertEquals(8, (float) $producto->fresh()->stock);
        $this->assertEqualsWithDelta(200.00, (float) $devolucion->subtotal, 0.01);
        $this->assertEqualsWithDelta(36.00, (float) $devolucion->itbis, 0.01);
        $this->assertEqualsWithDelta(236.00, (float) $devolucion->total, 0.01);
        $this->assertEquals(2, (float) $devolucion->detalles()->first()->cantidad);

        // La compra original no se toca: sigue cuadrando con la factura física.
        $this->assertEqualsWithDelta(1180.00, (float) $compra->fresh()->total, 0.01);
    }

    public function test_no_permite_devolver_mas_de_lo_comprado(): void
    {
        [$compra, $detalle, , $user] = $this->crearCompraDe10Unidades();

        $this->expectException(RuntimeException::class);

        app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha'     => now(),
            'motivo'    => 'Faltante',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 11],
            ],
        ], $user->id);
    }

    public function test_no_permite_devolver_mas_de_lo_disponible_tras_una_devolucion_previa(): void
    {
        [$compra, $detalle, , $user] = $this->crearCompraDe10Unidades();

        app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha'     => now(),
            'motivo'    => 'Primera devolución',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 6],
            ],
        ], $user->id);

        $this->assertEqualsWithDelta(4.0, $detalle->fresh()->cantidadDisponibleParaDevolver(), 0.001);

        $this->expectException(RuntimeException::class);

        app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha'     => now(),
            'motivo'    => 'Segunda devolución',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 5],
            ],
        ], $user->id);
    }

    public function test_no_permite_devolver_de_una_compra_anulada(): void
    {
        // Anular la compra baja el stock hasta el mínimo (0) y dispara la notificación
        // de stock bajo de ProductoObserver, que requiere que el permiso exista.
        $this->seed(RolePermissionSeeder::class);

        [$compra, $detalle, , $user] = $this->crearCompraDe10Unidades();

        app(CompraService::class)->anular($compra, 'Error de digitación', $user->id);

        $this->expectException(RuntimeException::class);

        app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha'     => now(),
            'motivo'    => 'Faltante',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 1],
            ],
        ], $user->id);
    }

    public function test_anular_devolucion_revierte_stock(): void
    {
        [$compra, $detalle, $producto, $user] = $this->crearCompraDe10Unidades();

        $devolucion = app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha'     => now(),
            'motivo'    => 'Faltante',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 3],
            ],
        ], $user->id);

        $this->assertEquals(7, (float) $producto->fresh()->stock);

        app(DevolucionCompraService::class)->anular($devolucion, 'Se recuperó la mercancía', $user->id);

        $this->assertEquals(10, (float) $producto->fresh()->stock);
        $this->assertTrue($devolucion->fresh()->estaAnulada());
        // La devolución anulada ya no cuenta como "devuelto": vuelve a estar disponible el total.
        $this->assertEqualsWithDelta(10.0, $detalle->fresh()->cantidadDisponibleParaDevolver(), 0.001);
    }

    public function test_no_permite_anular_una_devolucion_dos_veces(): void
    {
        [$compra, $detalle, , $user] = $this->crearCompraDe10Unidades();

        $devolucion = app(DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha'     => now(),
            'motivo'    => 'Faltante',
            'lineas' => [
                ['detalle_compra_id' => $detalle->id, 'cantidad' => 1],
            ],
        ], $user->id);

        app(DevolucionCompraService::class)->anular($devolucion, 'Motivo', $user->id);

        $this->expectException(RuntimeException::class);

        app(DevolucionCompraService::class)->anular($devolucion->fresh(), 'Motivo', $user->id);
    }
}
