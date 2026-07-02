<?php

namespace Tests\Feature;

use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Filament\Resources\MovimientoInventarioResource;
use App\Filament\Resources\MovimientoInventarioResource\Pages\ListMovimientoInventarios;
use App\Models\Producto;
use App\Models\User;
use App\Services\InventarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KardexTest extends TestCase
{
    use RefreshDatabase;

    public function test_kardex_visible_solo_con_permiso_gestionar_inventario(): void
    {
        Permission::firstOrCreate(['name' => 'gestionar_inventario', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'registrar_ventas', 'guard_name' => 'web']);

        $almacenista = Role::firstOrCreate(['name' => 'Almacenista', 'guard_name' => 'web']);
        $almacenista->syncPermissions(['gestionar_inventario']);

        $vendedor = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $vendedor->syncPermissions(['registrar_ventas']);

        $userAlmacenista = User::factory()->create();
        $userAlmacenista->assignRole('Almacenista');

        $userVendedor = User::factory()->create();
        $userVendedor->assignRole('Vendedor');

        $this->assertTrue($userAlmacenista->can('viewAny', \App\Models\MovimientoInventario::class));
        $this->assertFalse($userVendedor->can('viewAny', \App\Models\MovimientoInventario::class));

        $producto = Producto::create([
            'codigo' => 'K-2', 'nombre' => 'Kardex Test 2', 'tipo' => TipoProducto::PRODUCTO->value,
            'costo' => 5, 'precio' => 10, 'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            'controla_stock' => true, 'stock' => 0, 'stock_minimo' => 2, 'activo' => true,
        ]);

        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $producto, TipoMovimiento::ENTRADA, OrigenMovimiento::COMPRA, 20.0, null, $userAlmacenista->id, 'stock inicial'
        ));

        Livewire::actingAs($userAlmacenista)
            ->test(ListMovimientoInventarios::class)
            ->assertOk()
            ->assertCanSeeTableRecords([\App\Models\MovimientoInventario::first()])
            ->filterTable('producto_id', $producto->id)
            ->assertCanSeeTableRecords([\App\Models\MovimientoInventario::first()]);
    }

    public function test_no_puede_crear_movimientos_a_mano(): void
    {
        $this->assertFalse(MovimientoInventarioResource::canCreate());
    }
}
