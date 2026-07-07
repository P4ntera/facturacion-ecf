<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductoResource\Pages\ListProductos;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MaestrosActivoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'gestionar_maestros', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['gestionar_maestros']);

        $this->usuario = User::factory()->create();
        $this->usuario->assignRole('Vendedor');
    }

    public function test_ningun_maestro_puede_borrarse_fisicamente(): void
    {
        $categoria = Categoria::create(['nombre' => 'Categoría', 'activo' => true]);
        $cliente = Cliente::create(['nombre' => 'Cliente', 'tipo_documento' => 'cedula', 'activo' => true]);
        $proveedor = Proveedor::create(['rnc' => '123456789', 'nombre' => 'Proveedor', 'estado' => 'ACTIVO', 'activo' => true]);
        $producto = Producto::create([
            'codigo' => 'P1', 'nombre' => 'Producto', 'tipo' => 'producto',
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => '18',
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $this->assertFalse($this->usuario->can('delete', $categoria));
        $this->assertFalse($this->usuario->can('delete', $cliente));
        $this->assertFalse($this->usuario->can('delete', $proveedor));
        $this->assertFalse($this->usuario->can('delete', $producto));
    }

    public function test_desactivar_un_producto_lo_oculta_de_la_lista_por_defecto_sin_borrarlo(): void
    {
        $activo = Producto::create([
            'codigo' => 'ACT-1', 'nombre' => 'Producto activo', 'tipo' => 'producto',
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => '18',
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $inactivo = Producto::create([
            'codigo' => 'INA-1', 'nombre' => 'Producto inactivo', 'tipo' => 'producto',
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => '18',
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => false,
        ]);

        Livewire::actingAs($this->usuario)
            ->test(ListProductos::class)
            ->assertCanSeeTableRecords([$activo])
            ->assertCanNotSeeTableRecords([$inactivo])
            ->filterTable('activo', null)
            ->assertCanSeeTableRecords([$activo, $inactivo]);

        // Sigue en la base de datos: el "desactivar" nunca fue un delete.
        $this->assertDatabaseHas('productos', ['id' => $inactivo->id]);
    }
}
