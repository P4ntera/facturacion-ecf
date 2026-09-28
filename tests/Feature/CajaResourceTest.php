<?php

namespace Tests\Feature;

use App\Filament\Resources\CajaResource;
use App\Filament\Resources\CajaResource\Pages\CreateCaja;
use App\Filament\Resources\CajaResource\Pages\ListCajas;
use App\Models\Caja;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CajaResourceTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    public function test_administrador_tiene_los_permisos_de_cajas(): void
    {
        $admin = $this->administrador();

        $this->assertTrue($admin->can('cajas.ver'));
        $this->assertTrue($admin->can('cajas.crear'));
        $this->assertTrue($admin->can('cajas.editar'));
    }

    public function test_vendedor_no_ve_el_mantenimiento_de_cajas(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $vendedor = User::factory()->create();
        $vendedor->assignRole('Vendedor');

        $this->actingAs($vendedor)
            ->get(CajaResource::getUrl('index', tenant: $this->empresaDefault))
            ->assertForbidden();
    }

    public function test_crear_caja_la_asocia_a_la_empresa_y_genera_token(): void
    {
        Livewire::actingAs($this->administrador())
            ->test(CreateCaja::class)
            ->fillForm(['nombre' => 'Caja 1', 'codigo' => 'C01', 'activo' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $caja = Caja::sole();
        $this->assertSame($this->empresaDefault->id, $caja->empresa_id);
        $this->assertSame(64, strlen($caja->display_token));
    }

    public function test_nombre_y_codigo_son_unicos_dentro_de_la_empresa(): void
    {
        Caja::create(['nombre' => 'Caja 1', 'codigo' => 'C01']);

        Livewire::actingAs($this->administrador())
            ->test(CreateCaja::class)
            ->fillForm(['nombre' => 'Caja 1', 'codigo' => 'C01', 'activo' => true])
            ->call('create')
            ->assertHasFormErrors(['nombre' => 'unique', 'codigo' => 'unique']);
    }

    public function test_editar_muestra_la_url_del_display_y_regenerar_la_cambia(): void
    {
        $admin = $this->administrador();
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $anterior = $caja->display_token;

        $this->actingAs($admin)
            ->get(CajaResource::getUrl('edit', ['record' => $caja], tenant: $this->empresaDefault))
            ->assertOk()
            ->assertSee(route('display.cliente', $anterior));

        Livewire::actingAs($admin)
            ->test(ListCajas::class)
            ->callTableAction('regenerarToken', $caja);

        $this->assertNotSame($anterior, $caja->refresh()->display_token);
    }

    public function test_sin_cajas_editar_no_puede_editar(): void
    {
        Permission::firstOrCreate(['name' => 'cajas.ver', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['empresa_id' => $this->empresaDefault->id, 'name' => 'Supervisor', 'guard_name' => 'web']);
        $rol->syncPermissions(['cajas.ver']);
        $usuario = User::factory()->create();
        $usuario->assignRole('Supervisor');
        $caja = Caja::create(['nombre' => 'Caja 1']);

        $this->actingAs($usuario)
            ->get(CajaResource::getUrl('index', tenant: $this->empresaDefault))
            ->assertOk();

        $this->actingAs($usuario)
            ->get(CajaResource::getUrl('edit', ['record' => $caja], tenant: $this->empresaDefault))
            ->assertForbidden();
    }
}
