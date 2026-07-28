<?php

namespace Tests\Feature;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    public function test_la_pagina_indice_carga_para_quien_tiene_roles_gestionar(): void
    {
        $this->actingAs($this->admin())
            ->get(RoleResource::getUrl('index'))
            ->assertOk();
    }

    public function test_usuario_sin_permiso_no_puede_entrar(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        $this->actingAs($usuario)
            ->get(RoleResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_crear_un_rol_con_permisos_de_la_matriz(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'Cajero',
                'permisos' => [
                    'Ventas' => ['pos.acceder', 'ventas.imprimir'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rol = Role::where('name', 'Cajero')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['pos.acceder', 'ventas.imprimir'],
            $rol->permissions->pluck('name')->all(),
        );
    }

    public function test_la_tabla_muestra_cantidad_de_permisos_y_usuarios(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(ListRoles::class)
            ->assertTableColumnStateSet('usuarios_count', 1, Role::where('name', 'Administrador')->first());
    }

    public function test_no_se_puede_eliminar_el_rol_administrador(): void
    {
        $admin = $this->admin();
        // Un segundo administrador para que la protección de "no te quites a ti mismo" no sea
        // la que bloquee la eliminación: aquí se prueba específicamente la protección del rol.
        User::factory()->create()->assignRole('Administrador');

        $rolAdministrador = Role::where('name', 'Administrador')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListRoles::class)
            ->callTableAction('eliminar', $rolAdministrador)
            ->assertNotified();

        $this->assertNotNull($rolAdministrador->fresh());
    }

    public function test_no_se_puede_eliminar_un_rol_con_usuarios_asignados(): void
    {
        $admin = $this->admin();

        $rolVendedor = Role::where('name', 'Vendedor')->firstOrFail();
        User::factory()->create()->assignRole('Vendedor');

        Livewire::actingAs($admin)
            ->test(ListRoles::class)
            ->callTableAction('eliminar', $rolVendedor)
            ->assertNotified();

        $this->assertNotNull($rolVendedor->fresh());
    }

    public function test_se_puede_eliminar_un_rol_vacio_sin_usuarios(): void
    {
        $admin = $this->admin();

        $rolVacio = Role::create(['name' => 'Rol vacío', 'guard_name' => 'web']);

        Livewire::actingAs($admin)
            ->test(ListRoles::class)
            ->callTableAction('eliminar', $rolVacio);

        $this->assertNull($rolVacio->fresh());
    }

    public function test_no_se_puede_quitar_roles_gestionar_al_rol_administrador(): void
    {
        $admin = $this->admin();
        // Segundo administrador para aislar esta protección de la de "no te quites a ti mismo".
        User::factory()->create()->assignRole('Administrador');

        $rolAdministrador = Role::where('name', 'Administrador')->firstOrFail();
        $permisosOriginales = $rolAdministrador->permissions->pluck('name')->all();

        Livewire::actingAs($admin)
            ->test(EditRole::class, ['record' => $rolAdministrador->getKey()])
            ->fillForm([
                'permisos' => [
                    'Configuración' => ['empresa.administrar'], // sin roles.gestionar
                ],
            ])
            ->call('save')
            ->assertNotified();

        $this->assertEqualsCanonicalizing($permisosOriginales, $rolAdministrador->fresh()->permissions->pluck('name')->all());
    }

    public function test_un_usuario_no_puede_quitarse_a_si_mismo_el_acceso_a_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $rolPersonalizado = Role::create(['name' => 'Gerente', 'guard_name' => 'web']);
        $rolPersonalizado->syncPermissions(['roles.gestionar']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Gerente');

        Livewire::actingAs($usuario)
            ->test(EditRole::class, ['record' => $rolPersonalizado->getKey()])
            ->fillForm([
                'permisos' => [
                    'Ventas' => ['pos.acceder'], // sin roles.gestionar
                ],
            ])
            ->call('save')
            ->assertNotified();

        $this->assertTrue($rolPersonalizado->fresh()->hasPermissionTo('roles.gestionar'));
    }
}
