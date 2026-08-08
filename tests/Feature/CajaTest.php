<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Pages\Caja;
use App\Models\Producto;
use App\Models\Role;
use App\Models\SecuenciaNcf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CajaTest extends TestCase
{
    use RefreshDatabase;

    private function cajero(): User
    {
        Permission::firstOrCreate(['name' => 'pos.acceder', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['empresa_id' => $this->empresaDefault->id, 'name' => 'Cajero', 'guard_name' => 'web']);
        $rol->syncPermissions(['pos.acceder']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Cajero');

        return $usuario;
    }

    private function producto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo' => 'CAJA-001',
            'nombre' => 'Producto Caja',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 5,
            'stock_minimo' => 1,
            'activo' => true,
        ], $overrides));
    }

    private function secuenciaConsumo(): void
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

    public function test_por_defecto_el_tipo_de_comprobante_es_consumo_sin_mostrar_el_selector_tecnico(): void
    {
        Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->assertSet('creditoFiscal', false)
            ->assertSet('tipoComprobante', TipoComprobante::FACTURA_CONSUMO->value)
            ->assertDontSee('Tipo de comprobante')
            ->assertDontSee('Próximo e-NCF');
    }

    public function test_activar_credito_fiscal_cambia_el_tipo_de_comprobante_a_31(): void
    {
        Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->set('creditoFiscal', true)
            ->assertSet('tipoComprobante', TipoComprobante::FACTURA_CREDITO_FISCAL->value);
    }

    public function test_desactivar_credito_fiscal_vuelve_a_consumo(): void
    {
        Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->set('creditoFiscal', true)
            ->set('creditoFiscal', false)
            ->assertSet('tipoComprobante', TipoComprobante::FACTURA_CONSUMO->value);
    }

    public function test_cobrar_registra_la_venta_como_factura_de_consumo_por_defecto(): void
    {
        $this->secuenciaConsumo();
        $producto = $this->producto();

        $componente = Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->call('abrirCaja', '500.00')
            ->call('agregarProducto', $producto->id)
            ->call('cobrar');

        $venta = \App\Models\Venta::latest('id')->first();

        $this->assertNotNull($venta);
        $this->assertSame(TipoComprobante::FACTURA_CONSUMO, $venta->tipo_comprobante);
    }

    public function test_carrito_y_arqueo_funcionan_igual_que_en_facturacion_por_herencia(): void
    {
        $producto = $this->producto();

        Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->call('agregarProducto', $producto->id)
            ->assertSet('carrito.0.producto_id', $producto->id)
            ->assertSet('totales.total', '118.00');
    }

    public function test_bajar_la_cantidad_a_cero_quita_el_producto_del_carrito(): void
    {
        $producto = $this->producto();

        Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->call('agregarProducto', $producto->id)
            ->set('carrito.0.cantidad', 0)
            ->assertSet('carrito', []);
    }

    public function test_bajar_la_cantidad_por_debajo_de_cero_tambien_quita_el_producto(): void
    {
        $producto = $this->producto();

        Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->call('agregarProducto', $producto->id)
            ->set('carrito.0.cantidad', -3)
            ->assertSet('carrito', []);
    }

    public function test_la_cantidad_ingresada_se_trunca_a_entero(): void
    {
        $producto = $this->producto(['stock' => 10]);

        Livewire::actingAs($this->cajero())
            ->test(Caja::class)
            ->call('agregarProducto', $producto->id)
            ->set('carrito.0.cantidad', '2.9')
            ->assertSet('carrito.0.cantidad', 2);
    }
}
