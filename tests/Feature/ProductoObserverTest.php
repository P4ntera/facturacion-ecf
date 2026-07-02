<?php

namespace Tests\Feature;

use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Models\Producto;
use App\Models\User;
use App\Services\InventarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductoObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifica_a_usuarios_con_permiso_cuando_stock_baja_del_minimo(): void
    {
        Permission::firstOrCreate(['name' => 'gestionar_inventario', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Almacenista', 'guard_name' => 'web']);
        $rol->syncPermissions(['gestionar_inventario']);

        $almacenista = User::factory()->create();
        $almacenista->assignRole('Almacenista');

        $otro = User::factory()->create(); // sin permiso, no debe notificarse

        $producto = Producto::create([
            'codigo' => 'OBS-1', 'nombre' => 'Producto Observado', 'tipo' => TipoProducto::PRODUCTO->value,
            'costo' => 5, 'precio' => 10, 'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            'controla_stock' => true, 'stock' => 10, 'stock_minimo' => 5, 'activo' => true,
        ]);

        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $producto, TipoMovimiento::SALIDA, OrigenMovimiento::VENTA, 6.0, null, $almacenista->id
        ));

        $this->assertEquals(4, $producto->fresh()->stock);
        $this->assertSame(1, $almacenista->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $otro->fresh()->unreadNotifications()->count());
    }
}
