<?php

namespace Tests\Feature;

use App\Filament\Resources\DescuentoResource;
use App\Filament\Resources\DescuentoResource\Pages\CreateDescuento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DescuentoResourceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'descuentos.ver', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'descuentos.crear', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Rol-descuentos', 'guard_name' => 'web']);
        $rol->syncPermissions(['descuentos.ver', 'descuentos.crear']);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    public function test_crea_un_descuento_desde_el_formulario_de_filament(): void
    {
        Livewire::actingAs($this->usuarioAutorizado())
            ->test(CreateDescuento::class)
            ->fillForm([
                'nombre' => 'Empleado',
                'porcentaje' => 10,
                'activo' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('descuentos', [
            'nombre' => 'Empleado',
            'porcentaje' => 10,
        ]);
    }

    public function test_un_usuario_sin_permiso_no_puede_ver_el_listado(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(DescuentoResource::getUrl('index'))
            ->assertForbidden();
    }
}
