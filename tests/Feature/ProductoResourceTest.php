<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Resources\ProductoResource\Pages\CreateProducto;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductoResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_producto_desde_el_formulario_de_filament(): void
    {
        Permission::firstOrCreate(['name' => 'gestionar_maestros', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['gestionar_maestros']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        Livewire::actingAs($usuario)
            ->test(CreateProducto::class)
            ->fillForm([
                'codigo'         => 'TEST-001',
                'nombre'         => 'Producto de prueba',
                'tipo'           => TipoProducto::PRODUCTO->value,
                'precio'         => 100,
                'costo'          => 50,
                'tasa_itbis'     => TasaItbis::DIECIOCHO->value,
                'controla_stock' => true,
                'stock_minimo'   => 5,
                'activo'         => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('productos', [
            'codigo' => 'TEST-001',
            'nombre' => 'Producto de prueba',
        ]);
    }
}
