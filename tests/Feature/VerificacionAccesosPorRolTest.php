<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageEmpresa;
use App\Filament\Pages\ManageFacturacion;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Pages\Reportes\ReporteVentas;
use App\Filament\Resources\AuditoriaResource;
use App\Filament\Resources\ClienteResource;
use App\Filament\Resources\CompraResource;
use App\Filament\Resources\ImpresoraResource;
use App\Filament\Resources\ProductoResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\SecuenciaNcfResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\VentaResource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verificación manual (A.4 del prompt de roles granulares) convertida en test permanente: crea
 * el rol "Cajero" desde el panel tal como lo haría un administrador, confirma que un usuario con
 * ese rol solo ve el POS, que las protecciones de borrado de RoleResource funcionan, y que el
 * Administrador conserva acceso a todo.
 */
class VerificacionAccesosPorRolTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    public function test_1_crear_el_rol_cajero_desde_el_panel_con_solo_pos_y_ventas_imprimir(): void
    {
        Livewire::actingAs($this->administrador())
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'Cajero',
                'permisos' => [
                    'Ventas' => ['pos.acceder', 'ventas.imprimir'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cajero = Role::where('name', 'Cajero')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['pos.acceder', 'ventas.imprimir'],
            $cajero->permissions->pluck('name')->all(),
        );
    }

    public function test_2_un_usuario_cajero_solo_ve_el_pos_el_resto_del_menu_se_ajusta(): void
    {
        $this->administrador();

        $cajero = Role::create(['name' => 'Cajero', 'guard_name' => 'web']);
        $cajero->syncPermissions(['pos.acceder', 'ventas.imprimir']);

        $usuarioCajero = User::factory()->create();
        $usuarioCajero->assignRole('Cajero');

        $this->actingAs($usuarioCajero);

        // Puede entrar al POS...
        $this->assertTrue(PuntoDeVenta::canAccess());

        // ...pero no a ningún otro Resource/Page del panel: el menú se reduce a lo que puede ver.
        $this->assertFalse(VentaResource::canAccess(), 'no debería ver el listado de Ventas (solo pos.acceder + ventas.imprimir)');
        $this->assertFalse(ProductoResource::canAccess());
        $this->assertFalse(ClienteResource::canAccess());
        $this->assertFalse(CompraResource::canAccess());
        $this->assertFalse(ReporteVentas::canAccess());
        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(RoleResource::canAccess());
        $this->assertFalse(ImpresoraResource::canAccess());
        $this->assertFalse(SecuenciaNcfResource::canAccess());
        $this->assertFalse(AuditoriaResource::canAccess());
        $this->assertFalse(ManageEmpresa::canAccess());
        $this->assertFalse(ManageFacturacion::canAccess());

        // La página del POS en sí responde 200; cualquier otra, 403.
        $this->get(PuntoDeVenta::getUrl())->assertOk();
        $this->get(VentaResource::getUrl('index'))->assertForbidden();
        $this->get(ProductoResource::getUrl('index'))->assertForbidden();
    }

    public function test_3a_no_se_puede_eliminar_el_rol_administrador(): void
    {
        $admin = $this->administrador();
        User::factory()->create()->assignRole('Administrador'); // aislar de la protección de auto-bloqueo

        $rolAdministrador = Role::where('name', 'Administrador')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListRoles::class)
            ->callTableAction('eliminar', $rolAdministrador)
            ->assertNotified();

        $this->assertNotNull($rolAdministrador->fresh(), 'el rol Administrador debe seguir existiendo');
    }

    public function test_3b_no_se_puede_eliminar_un_rol_con_usuarios_asignados(): void
    {
        $admin = $this->administrador();

        $rolVendedor = Role::where('name', 'Vendedor')->firstOrFail();
        User::factory()->create()->assignRole('Vendedor');

        Livewire::actingAs($admin)
            ->test(ListRoles::class)
            ->callTableAction('eliminar', $rolVendedor)
            ->assertNotified();

        $this->assertNotNull($rolVendedor->fresh(), 'un rol con usuarios asignados no debe poder eliminarse');
    }

    public function test_4_el_administrador_sigue_viendo_todo(): void
    {
        $admin = $this->administrador();
        $this->actingAs($admin);

        $this->assertTrue(PuntoDeVenta::canAccess());
        $this->assertTrue(VentaResource::canAccess());
        $this->assertTrue(ProductoResource::canAccess());
        $this->assertTrue(ClienteResource::canAccess());
        $this->assertTrue(CompraResource::canAccess());
        $this->assertTrue(ReporteVentas::canAccess());
        $this->assertTrue(UserResource::canAccess());
        $this->assertTrue(RoleResource::canAccess());
        $this->assertTrue(ImpresoraResource::canAccess());
        $this->assertTrue(SecuenciaNcfResource::canAccess());
        $this->assertTrue(AuditoriaResource::canAccess());
        $this->assertTrue(ManageEmpresa::canAccess());
        $this->assertTrue(ManageFacturacion::canAccess());

        $this->get(PuntoDeVenta::getUrl())->assertOk();
        $this->get(VentaResource::getUrl('index'))->assertOk();
        $this->get(RoleResource::getUrl('index'))->assertOk();
    }
}
