<?php

namespace Tests\Feature;

use App\Enums\EstadoCompra;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\CompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CompraServiceTest extends TestCase
{
    use RefreshDatabase;

    private function crearProducto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo' => 'P-'.fake()->unique()->numerify('###'),
            'nombre' => 'Producto Test',
            'tipo' => TipoProducto::PRODUCTO->value,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 0,
            'activo' => true,
        ], $overrides));
    }

    public function test_crear_compra_incrementa_stock_y_actualiza_costo_producto(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = $this->crearProducto();
        $user = User::factory()->create();

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $user->id);

        $this->assertEquals(EstadoCompra::REGISTRADA, $compra->estado);
        $this->assertEquals(300.00, (float) $compra->subtotal);
        $this->assertEquals(54.00, (float) $compra->itbis); // 300 * 18%
        $this->assertEquals(354.00, (float) $compra->total);

        $detalle = $compra->detalles()->first();
        $this->assertEquals(TasaItbis::DIECIOCHO, $detalle->tasa_itbis);
        $this->assertEquals(60.00, (float) $detalle->costo_unitario);

        $producto->refresh();
        $this->assertEquals(15, (float) $producto->stock); // 10 + 5
        $this->assertEquals(60.00, (float) $producto->costo); // costo actualizado

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'tipo' => 'entrada',
            'origen' => 'compra',
            'referencia_id' => $compra->id,
        ]);
    }

    public function test_crear_compra_vincula_producto_y_proveedor_en_el_catalogo(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();

        app(CompraService::class)->crear([
            'proveedor_id'     => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf'              => null,
            'fecha'            => now(),
            'itbis_incluido'   => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $user->id);

        $this->assertDatabaseHas('producto_proveedor', [
            'producto_id'      => $producto->id,
            'proveedor_id'     => $proveedor->id,
            'costo_referencia' => 60.00,
            'es_principal'     => true, // primer proveedor del producto
        ]);
    }

    public function test_comprar_de_nuevo_al_mismo_proveedor_solo_refresca_el_costo_referencia(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto  = $this->crearProducto();
        $user      = User::factory()->create();
        $service   = app(CompraService::class);

        $datosCompra = [
            'proveedor_id'     => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf'              => null,
            'fecha'            => now(),
            'itbis_incluido'   => false,
        ];

        $service->crear($datosCompra + ['lineas' => [
            ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 60],
        ]], $user->id);

        // El usuario marca a mano este vínculo como no-principal antes de la segunda compra.
        $producto->proveedores()->updateExistingPivot($proveedor->id, ['es_principal' => false]);

        $service->crear($datosCompra + ['lineas' => [
            ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 75],
        ]], $user->id);

        $this->assertDatabaseHas('producto_proveedor', [
            'producto_id'      => $producto->id,
            'proveedor_id'     => $proveedor->id,
            'costo_referencia' => 75.00,
            'es_principal'     => false, // no se toca en compras subsecuentes
        ]);
    }

    public function test_crear_compra_con_itbis_incluido_extrae_base_correctamente(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = $this->crearProducto(); // tasa 18%
        $user = User::factory()->create();

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => true,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 118],
            ],
        ], $user->id);

        $detalle = $compra->detalles()->first();
        $this->assertEqualsWithDelta(100.00, (float) $detalle->costo_unitario, 0.01);
        $this->assertEqualsWithDelta(18.00, (float) $detalle->itbis_monto, 0.01);
        $this->assertEqualsWithDelta(118.00, (float) $compra->total, 0.01);

        $this->assertEqualsWithDelta(100.00, (float) $producto->fresh()->costo, 0.01);
    }

    public function test_producto_sin_tasa_gravada_no_genera_itbis(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = $this->crearProducto(['tasa_itbis' => TasaItbis::CERO->value]);
        $user = User::factory()->create();

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => true, // no debe afectar nada si la tasa es exenta
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 2, 'costo_unitario' => 40],
            ],
        ], $user->id);

        $this->assertEquals(0.0, (float) $compra->itbis);
        $this->assertEquals(80.00, (float) $compra->total);
    }

    public function test_anular_compra_revierte_stock(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = $this->crearProducto();
        $user = User::factory()->create();

        $service = app(CompraService::class);
        $compra = $service->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $user->id);

        $this->assertEquals(15, (float) $producto->fresh()->stock);

        $compra = $service->anular($compra, 'Error de captura', $user->id);

        $this->assertEquals(EstadoCompra::ANULADA, $compra->estado);
        $this->assertEquals('Error de captura', $compra->motivo_anulacion);
        $this->assertNotNull($compra->anulada_en);
        $this->assertEquals(10, (float) $producto->fresh()->stock);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'tipo' => 'salida',
            'origen' => 'anulacion',
        ]);
    }

    public function test_no_permite_anular_dos_veces(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = $this->crearProducto();
        $user = User::factory()->create();

        $service = app(CompraService::class);
        $compra = $service->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $user->id);

        $service->anular($compra, 'Motivo', $user->id);

        $this->expectException(RuntimeException::class);
        $service->anular($compra, 'Otro motivo', $user->id);
    }

    public function test_costo_ultima_linea_gana_si_mismo_producto_repetido(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = $this->crearProducto();
        $user = User::factory()->create();

        app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 55],
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 70],
            ],
        ], $user->id);

        $this->assertEquals(70.00, (float) $producto->fresh()->costo);
    }

    public function test_proveedor_informal_genera_ncf_automatico_ignorando_lo_digitado(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::COMPRAS->value,
            'prefijo' => 'B41',
            'secuencia_actual' => 1,
            'secuencia_hasta' => 100,
            'vencimiento' => today()->addYear(),
            'activa' => true,
        ]);

        $proveedor = Proveedor::factory()->informal()->create();
        $producto = $this->crearProducto();
        $user = User::factory()->create();

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL, // debe ignorarse
            'ncf' => 'LOQUESEA0001',                          // debe ignorarse
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $user->id);

        $this->assertEquals(TipoComprobante::COMPRAS, $compra->tipo_comprobante);
        $this->assertEquals('B410000000001', $compra->ncf);
    }

    public function test_proveedor_formal_usa_ncf_digitado_manualmente(): void
    {
        $proveedor = Proveedor::factory()->create(); // formal por defecto
        $producto = $this->crearProducto();
        $user = User::factory()->create();

        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL,
            'ncf' => 'B0100000001',
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $user->id);

        $this->assertEquals(TipoComprobante::FACTURA_CREDITO_FISCAL, $compra->tipo_comprobante);
        $this->assertEquals('B0100000001', $compra->ncf);
    }

    public function test_guarda_monto_total_factura_aunque_no_coincida_con_el_calculado(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = $this->crearProducto();
        $user = User::factory()->create();

        // Total calculado real: 5 * 60 = 300 + 18% = 354. Se digita un monto distinto
        // (ej. la factura trae flete aparte) y debe guardarse sin bloquear ni corregirse.
        $compra = app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'monto_total_factura' => 400.00,
            'lineas' => [
                ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 60],
            ],
        ], $user->id);

        $this->assertEqualsWithDelta(354.00, (float) $compra->total, 0.01);
        $this->assertEqualsWithDelta(400.00, (float) $compra->monto_total_factura, 0.01);
    }

    public function test_anular_compras_solo_lo_tiene_administrador(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Administrador');

        $almacenista = User::factory()->create();
        $almacenista->assignRole('Almacenista');

        $this->assertTrue($admin->can('compras.anular'));
        $this->assertFalse($almacenista->can('compras.anular'));
    }
}
