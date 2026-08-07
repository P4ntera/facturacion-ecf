<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Filament\Resources\ProductoResource\Pages\CreateProducto;
use App\Filament\Resources\ProductoResource\Pages\ListProductos;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductoPresentacionesTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    private function datosBaseProducto(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'PRE-'.fake()->unique()->numerify('###'),
            'nombre' => 'Refresco Cola',
            'tipo' => TipoProducto::PRODUCTO->value,
            'costo' => 30,
            'tasa_itbis' => TasaItbis::DIECIOCHO->value,
        ], $overrides);
    }

    public function test_crea_producto_contable_con_varias_presentaciones(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateProducto::class)
            ->fillForm(array_merge($this->datosBaseProducto(), [
                'tipo_venta' => TipoVenta::CONTABLE->value,
                'precio' => 50,
                'presentaciones' => [
                    ['nombre' => 'Unidad', 'factor' => 1, 'codigo_barra' => '750101', 'precio' => 50, 'es_base' => true, 'activa' => true],
                    ['nombre' => 'Caja', 'factor' => 24, 'codigo_barra' => '750103', 'precio' => 1000, 'es_base' => false, 'activa' => true],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $producto = Producto::where('codigo', 'like', 'PRE-%')->firstOrFail();

        $this->assertSame(TipoVenta::CONTABLE, $producto->tipo_venta);
        $this->assertSame(2, $producto->presentaciones()->count());

        $base = $producto->presentacionBase();
        $this->assertNotNull($base);
        $this->assertSame('750101', $base->codigo_barra);
        $this->assertSame($this->empresaDefault->id, $base->empresa_id);

        $caja = $producto->presentaciones()->where('nombre', 'Caja')->first();
        $this->assertSame(24.0, (float) $caja->factor);
        $this->assertSame(1000.0, (float) $caja->precio);
    }

    public function test_rechaza_presentaciones_sin_exactamente_una_base(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateProducto::class)
            ->fillForm(array_merge($this->datosBaseProducto(), [
                'tipo_venta' => TipoVenta::CONTABLE->value,
                'precio' => 50,
                'presentaciones' => [
                    ['nombre' => 'Unidad', 'factor' => 1, 'precio' => 50, 'es_base' => false, 'activa' => true],
                    ['nombre' => 'Caja', 'factor' => 24, 'precio' => 1000, 'es_base' => false, 'activa' => true],
                ],
            ]))
            ->call('create')
            ->assertHasFormErrors(['presentaciones']);
    }

    public function test_producto_pesado_no_requiere_presentaciones_y_usa_precio_por_peso(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateProducto::class)
            ->fillForm(array_merge($this->datosBaseProducto([
                'codigo' => 'PES-'.fake()->unique()->numerify('###'),
                'nombre' => 'Habichuelas',
            ]), [
                'tipo_venta' => TipoVenta::PESADO->value,
                'unidad_base' => 'libra',
                'precio_por_peso' => 45,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $producto = Producto::where('nombre', 'Habichuelas')->firstOrFail();

        $this->assertSame(TipoVenta::PESADO, $producto->tipo_venta);
        $this->assertSame('libra', $producto->unidad_base);
        $this->assertSame(45.0, (float) $producto->precio_por_peso);
        $this->assertSame(0, $producto->presentaciones()->count());
    }

    public function test_la_busqueda_de_la_tabla_encuentra_por_codigo_de_barras_de_una_presentacion(): void
    {
        $usuario = $this->usuarioConPermiso();

        $producto = Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'PRE-500',
            'nombre' => 'Refresco con presentaciones',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::CONTABLE,
            'costo' => 30,
            'precio' => 50,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
        $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Caja',
            'factor' => 24,
            'codigo_barra' => '750103',
            'precio' => 1000,
            'es_base' => false,
            'activa' => true,
        ]);

        $otro = Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'PRE-501',
            'nombre' => 'Otro producto',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::CONTABLE,
            'costo' => 10,
            'precio' => 20,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        Livewire::actingAs($usuario)
            ->test(ListProductos::class)
            ->searchTable('750103')
            ->assertCanSeeTableRecords([$producto])
            ->assertCanNotSeeTableRecords([$otro]);
    }
}
