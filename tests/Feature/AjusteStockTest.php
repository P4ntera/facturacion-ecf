<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Resources\ProductoResource\Pages\ListProductos;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AjusteStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_ajustar_stock_action_registra_movimiento_y_actualiza_stock(): void
    {
        Permission::firstOrCreate(['name' => 'gestionar_inventario', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'gestionar_maestros', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Almacenista', 'guard_name' => 'web']);
        $rol->syncPermissions(['gestionar_inventario', 'gestionar_maestros']);

        $user = User::factory()->create();
        $user->assignRole('Almacenista');

        $producto = Producto::create([
            'codigo' => 'T-1', 'nombre' => 'Prod Test', 'tipo' => TipoProducto::PRODUCTO->value,
            'costo' => 5, 'precio' => 10, 'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            'controla_stock' => true, 'stock' => 10, 'stock_minimo' => 2, 'activo' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ListProductos::class)
            ->callTableAction('ajustarStock', $producto, data: [
                'tipo' => 'entrada',
                'cantidad' => 5,
                'observacion' => 'Compra manual',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(15, $producto->fresh()->stock);
        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'tipo' => 'entrada',
            'stock_anterior' => 10,
            'stock_nuevo' => 15,
        ]);

        // Salida mayor al disponible: no debe dejar stock negativo y debe mostrar error.
        Livewire::actingAs($user)
            ->test(ListProductos::class)
            ->callTableAction('ajustarStock', $producto, data: [
                'tipo' => 'salida',
                'cantidad' => 999,
                'observacion' => null,
            ]);

        $this->assertEquals(15, $producto->fresh()->stock);
    }
}
