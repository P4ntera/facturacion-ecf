<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoComanda;
use App\Enums\EstadoMesa;
use App\Enums\EstadoPreparacion;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\AreaRestaurante;
use App\Models\Empresa;
use App\Models\EmpresaConfiguracion;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use App\Services\ComandaService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ComandaTest extends TestCase
{
    use RefreshDatabase;

    private ComandaService $service;
    private Empresa $empresa;
    private User $admin;
    private Producto $producto;
    private Mesa $mesa;
    private AreaRestaurante $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->empresa = $this->empresaDefault;
        $this->admin = User::factory()->create(['empresa_id' => $this->empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($this->admin, $this->empresa);
        $this->actingAs($this->admin);

        EmpresaConfiguracion::updateOrCreate(
            ['empresa_id' => $this->empresa->id],
            ['permite_ventas_sin_comprobante' => true],
        );

        $this->producto = Producto::create([
            'empresa_id' => $this->empresa->id,
            'codigo' => 'PLATO-001',
            'nombre' => 'Hamburguesa Clásica',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 150,
            'precio' => 350,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'activo' => true,
        ]);

        $this->area = AreaRestaurante::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Salón Principal',
        ]);

        $this->mesa = Mesa::create([
            'empresa_id' => $this->empresa->id,
            'area_restaurante_id' => $this->area->id,
            'numero' => '1',
            'capacidad' => 4,
        ]);

        $this->service = app(ComandaService::class);
    }

    public function test_abrir_comanda_ocupa_mesa(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin, 2);

        $this->assertEquals(EstadoComanda::ABIERTA, $comanda->estado);
        $this->assertEquals('COM-00001', $comanda->numero);
        $this->mesa->refresh();
        $this->assertEquals(EstadoMesa::OCUPADA, $this->mesa->estado);
    }

    public function test_no_abrir_comanda_en_mesa_ocupada(): void
    {
        $this->service->abrir($this->empresa, $this->mesa, $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no está disponible');

        $this->service->abrir($this->empresa, $this->mesa, $this->admin);
    }

    public function test_agregar_producto_a_comanda(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $detalle = $this->service->agregarProducto($comanda, $this->producto, 2, 'sin cebolla');

        $this->assertEquals($this->producto->id, $detalle->producto_id);
        $this->assertEquals('2.000', $detalle->cantidad);
        $this->assertEquals($this->producto->precio, $detalle->precio_unitario);
        $this->assertEquals('sin cebolla', $detalle->notas);
        $this->assertEquals(EstadoPreparacion::PENDIENTE, $detalle->estado_preparacion);
    }

    public function test_enviar_a_cocina(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto);

        $enviados = $this->service->enviarACocina($comanda);

        $this->assertEquals(1, $enviados);
        $comanda->refresh();
        $this->assertEquals(EstadoComanda::EN_PREPARACION, $comanda->estado);

        $detalle = $comanda->detalles->first();
        $this->assertEquals(EstadoPreparacion::EN_PREPARACION, $detalle->estado_preparacion);
        $this->assertNotNull($detalle->enviado_cocina_en);
    }

    public function test_marcar_preparado_cambia_estado_comanda(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto);
        $this->service->enviarACocina($comanda);

        $detalle = $comanda->detalles()->first();
        $this->service->marcarPreparado($detalle);

        $detalle->refresh();
        $this->assertEquals(EstadoPreparacion::LISTO, $detalle->estado_preparacion);
        $this->assertNotNull($detalle->preparado_en);

        $comanda->refresh();
        $this->assertEquals(EstadoComanda::LISTA, $comanda->estado);
    }

    public function test_cerrar_comanda_genera_venta(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto, 2);
        $this->service->enviarACocina($comanda);

        $venta = $this->service->cerrar($comanda, $this->admin, ['sin_comprobante' => true]);

        $this->assertNotNull($venta->id);

        $comanda->refresh();
        $this->assertEquals(EstadoComanda::CERRADA, $comanda->estado);
        $this->assertEquals($venta->id, $comanda->venta_id);
        $this->assertNotNull($comanda->cerrada_en);

        $this->mesa->refresh();
        $this->assertEquals(EstadoMesa::DISPONIBLE, $this->mesa->estado);
    }

    public function test_cerrar_comanda_descuenta_stock(): void
    {
        $stockAntes = (float) $this->producto->stock;

        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto, 3);
        $this->service->cerrar($comanda, $this->admin, ['sin_comprobante' => true]);

        $this->producto->refresh();
        $this->assertEquals($stockAntes - 3, (float) $this->producto->stock);
    }

    public function test_cancelar_comanda_libera_mesa(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto);

        $this->service->cancelar($comanda);

        $comanda->refresh();
        $this->assertEquals(EstadoComanda::CANCELADA, $comanda->estado);

        $this->mesa->refresh();
        $this->assertEquals(EstadoMesa::DISPONIBLE, $this->mesa->estado);

        $detalle = $comanda->detalles->first();
        $this->assertEquals(EstadoPreparacion::CANCELADO, $detalle->estado_preparacion);
    }

    public function test_no_cancelar_comanda_cerrada(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto);
        $this->service->cerrar($comanda, $this->admin, ['sin_comprobante' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se puede cancelar');

        $comanda->refresh();
        $this->service->cancelar($comanda);
    }

    public function test_transferir_comanda_a_otra_mesa(): void
    {
        $mesa2 = Mesa::create([
            'empresa_id' => $this->empresa->id,
            'area_restaurante_id' => $this->area->id,
            'numero' => '2',
            'capacidad' => 4,
        ]);

        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);

        $this->service->transferir($comanda, $mesa2);

        $comanda->refresh();
        $this->assertEquals($mesa2->id, $comanda->mesa_id);

        $this->mesa->refresh();
        $this->assertEquals(EstadoMesa::DISPONIBLE, $this->mesa->estado);

        $mesa2->refresh();
        $this->assertEquals(EstadoMesa::OCUPADA, $mesa2->estado);
    }

    public function test_no_transferir_a_mesa_ocupada(): void
    {
        $mesa2 = Mesa::create([
            'empresa_id' => $this->empresa->id,
            'area_restaurante_id' => $this->area->id,
            'numero' => '2',
            'capacidad' => 4,
        ]);

        $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->abrir($this->empresa, $mesa2, $this->admin);

        $comanda1 = $this->mesa->comandaActiva;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no está disponible');

        $this->service->transferir($comanda1, $mesa2);
    }

    public function test_correlativo_por_empresa(): void
    {
        $c1 = $this->service->abrir($this->empresa, $this->mesa, $this->admin);

        $mesa2 = Mesa::create([
            'empresa_id' => $this->empresa->id,
            'area_restaurante_id' => $this->area->id,
            'numero' => '2',
        ]);
        $this->service->cancelar($c1);

        $c2 = $this->service->abrir($this->empresa, $this->mesa, $this->admin);

        $this->assertEquals('COM-00001', $c1->numero);
        $this->assertEquals('COM-00002', $c2->numero);
    }

    public function test_aislamiento_empresa(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);

        $otraEmpresa = Empresa::factory()->create();
        app(RolesEmpresaService::class)->sembrarRolesBase($otraEmpresa);

        $this->assertEquals($this->empresa->id, $comanda->empresa_id);

        $this->expectException(RuntimeException::class);
        $this->service->abrir($otraEmpresa, $this->mesa, $this->admin);
    }

    public function test_no_agregar_producto_a_comanda_cerrada(): void
    {
        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto);
        $this->service->cerrar($comanda, $this->admin, ['sin_comprobante' => true]);

        $comanda->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pueden agregar');

        $this->service->agregarProducto($comanda, $this->producto);
    }

    public function test_items_cancelados_no_entran_en_venta(): void
    {
        $producto2 = Producto::create([
            'empresa_id' => $this->empresa->id,
            'codigo' => 'PLATO-002',
            'nombre' => 'Papas Fritas',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 120,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'activo' => true,
        ]);

        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto, 1);
        $detalle2 = $this->service->agregarProducto($comanda, $producto2, 1);

        $detalle2->update(['estado_preparacion' => EstadoPreparacion::CANCELADO]);

        $venta = $this->service->cerrar($comanda, $this->admin, ['sin_comprobante' => true]);
        $venta->load('detalles');

        $this->assertCount(1, $venta->detalles);
        $this->assertEquals($this->producto->id, $venta->detalles->first()->producto_id);
    }

    public function test_multiples_detalles_un_preparado_no_cambia_comanda(): void
    {
        $producto2 = Producto::create([
            'empresa_id' => $this->empresa->id,
            'codigo' => 'PLATO-003',
            'nombre' => 'Ensalada',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 40,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'activo' => true,
        ]);

        $comanda = $this->service->abrir($this->empresa, $this->mesa, $this->admin);
        $this->service->agregarProducto($comanda, $this->producto);
        $this->service->agregarProducto($comanda, $producto2);
        $this->service->enviarACocina($comanda);

        $primerDetalle = $comanda->detalles()->first();
        $this->service->marcarPreparado($primerDetalle);

        $comanda->refresh();
        $this->assertEquals(EstadoComanda::EN_PREPARACION, $comanda->estado);
    }
}
