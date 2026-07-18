<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Resources\ProductoResource\Pages\CreateProducto;
use App\Filament\Resources\ProductoResource\Pages\ListProductos;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductoCodigoBarraTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    private function producto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo' => 'CB-'.fake()->unique()->numerify('###'),
            'nombre' => 'Producto con código',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 5,
            'precio' => 10,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ], $overrides));
    }

    public function test_crear_producto_con_codigo_de_barras(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateProducto::class)
            ->fillForm([
                'codigo' => 'CB-100',
                'codigo_barra' => '7501234567890',
                'nombre' => 'Producto escaneable',
                'tipo' => TipoProducto::PRODUCTO->value,
                'precio' => 100,
                'costo' => 50,
                'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('productos', ['codigo' => 'CB-100', 'codigo_barra' => '7501234567890']);
    }

    public function test_producto_sin_codigo_de_barras_se_guarda_sin_problema(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateProducto::class)
            ->fillForm([
                'codigo' => 'CB-101',
                'nombre' => 'Producto sin código de barras',
                'tipo' => TipoProducto::PRODUCTO->value,
                'precio' => 100,
                'costo' => 50,
                'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('productos', ['codigo' => 'CB-101', 'codigo_barra' => null]);
    }

    public function test_codigos_de_barras_duplicados_se_rechazan_con_mensaje_claro(): void
    {
        $this->producto(['codigo_barra' => '7501234567890']);

        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateProducto::class)
            ->fillForm([
                'codigo' => 'CB-102',
                'codigo_barra' => '7501234567890',
                'nombre' => 'Duplicado',
                'tipo' => TipoProducto::PRODUCTO->value,
                'precio' => 100,
                'costo' => 50,
                'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['codigo_barra' => 'unique']);
    }

    public function test_dos_productos_sin_codigo_de_barras_no_chocan_entre_si(): void
    {
        $this->producto(['codigo' => 'CB-200']);
        $this->producto(['codigo' => 'CB-201']);

        $this->assertDatabaseCount('productos', 2);
    }

    public function test_la_busqueda_de_la_tabla_encuentra_por_codigo_de_barras(): void
    {
        $usuario = $this->usuarioConPermiso();
        $producto = $this->producto(['codigo' => 'CB-300', 'codigo_barra' => '7709988776655']);
        $otro = $this->producto(['codigo' => 'CB-301', 'codigo_barra' => '1112223334445']);

        Livewire::actingAs($usuario)
            ->test(ListProductos::class)
            ->searchTable('7709988776655')
            ->assertCanSeeTableRecords([$producto])
            ->assertCanNotSeeTableRecords([$otro]);
    }
}
