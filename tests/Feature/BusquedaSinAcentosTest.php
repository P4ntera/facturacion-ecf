<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\ClienteResource\Pages\ListClientes;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Búsquedas insensibles a acentos y mayúsculas: Filament vía search_collation (config/database.php)
 * y las consultas manuales vía el macro whereLikeSinAcentos (AppServiceProvider).
 */
class BusquedaSinAcentosTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        foreach (['clientes.ver', 'pos.acceder', 'facturacion.acceder'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $rol = Role::firstOrCreate(['name' => 'Rol-busqueda', 'guard_name' => 'web']);
        $rol->syncPermissions(['clientes.ver', 'pos.acceder', 'facturacion.acceder']);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function cliente(string $nombre): Cliente
    {
        return Cliente::create(['nombre' => $nombre, 'activo' => true]);
    }

    public function test_listado_de_clientes_encuentra_sin_acentos_y_con_acentos(): void
    {
        $jose = $this->cliente('José García');
        $otro = $this->cliente('Pedro Martínez');
        $usuario = $this->usuario();

        foreach (['jose garcia', 'José', 'JOSE GARCÍA'] as $termino) {
            Livewire::actingAs($usuario)
                ->test(ListClientes::class)
                ->searchTable($termino)
                ->assertCanSeeTableRecords([$jose])
                ->assertCanNotSeeTableRecords([$otro]);
        }
    }

    public function test_pos_busca_clientes_sin_acentos(): void
    {
        $this->cliente('José García');

        $sugeridos = Livewire::actingAs($this->usuario())
            ->test(PuntoDeVenta::class)
            ->set('busquedaCliente', 'jose garcia')
            ->instance()
            ->clientesSugeridos();

        $this->assertSame(['José García'], $sugeridos->pluck('nombre')->all());
    }

    public function test_pos_busca_productos_sin_acentos(): void
    {
        Producto::create([
            'codigo' => 'ACE-001',
            'nombre' => 'Café Molido Peña',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $componente = Livewire::actingAs($this->usuario())->test(PuntoDeVenta::class);

        foreach (['cafe molido', 'pena', 'CAFÉ'] as $termino) {
            $filas = $componente->set('busquedaProducto', $termino)->instance()->resultadosBusqueda();

            $this->assertNotEmpty($filas, "No encontró el producto buscando '{$termino}'.");
        }
    }
}
