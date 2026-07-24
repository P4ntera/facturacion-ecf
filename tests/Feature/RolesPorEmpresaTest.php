<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductoResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Models\Empresa;
use App\Models\Role;
use App\Models\User;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verificación obligatoria de roles por empresa (PASO 7 del prompt de roles-por-empresa).
 * Complementa (no repite) AislamientoEntreEmpresasTest, que ya cubre el aislamiento de datos
 * de negocio del T1 — aquí el foco es roles/permisos.
 */
class RolesPorEmpresaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Catálogo global de permisos (RolesEmpresaService::sembrarRolesBase() los necesita ya
        // creados para poder hacer syncPermissions() al sembrar los roles base de cada empresa).
        $this->seed(RolePermissionSeeder::class);
    }

    private function crearEmpresa(string $razonSocial, string $rnc): Empresa
    {
        $empresa = Empresa::create(['razon_social' => $razonSocial, 'rnc' => $rnc]);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);

        return $empresa;
    }

    private function comoEmpresa(Empresa $empresa): void
    {
        Filament::setTenant($empresa, isQuiet: true);
        setPermissionsTeamId($empresa->id);
    }

    /** 1. Cada empresa nueva tiene sus propios roles base sembrados automáticamente. */
    public function test_1_cada_empresa_nueva_tiene_sus_roles_base_sembrados(): void
    {
        $empresaA = $this->crearEmpresa('Empresa A', '131000001');
        $tobogan = $this->crearEmpresa('Tobogán', '131000002');

        foreach ([$empresaA, $tobogan] as $empresa) {
            $roles = Role::where('empresa_id', $empresa->id)->pluck('name')->sort()->values();
            $this->assertEquals(['Administrador', 'Almacenista', 'Vendedor'], $roles->all());
        }

        // Son filas DISTINTAS por empresa, no un mismo rol global compartido.
        $administradorA = Role::where('empresa_id', $empresaA->id)->where('name', 'Administrador')->first();
        $administradorTobogan = Role::where('empresa_id', $tobogan->id)->where('name', 'Administrador')->first();
        $this->assertNotEquals($administradorA->id, $administradorTobogan->id);
    }

    /** 2. Mismo nombre de rol en dos empresas, con permisos distintos, sin conflicto. */
    public function test_2_mismo_nombre_de_rol_en_dos_empresas_con_permisos_distintos(): void
    {
        $empresaA = $this->crearEmpresa('Empresa A', '131000001');
        $tobogan = $this->crearEmpresa('Tobogán', '131000002');

        $this->comoEmpresa($empresaA);
        $cajeroA = Role::create(['name' => 'Cajero', 'guard_name' => 'web', 'empresa_id' => $empresaA->id]);
        $cajeroA->syncPermissions(['pos.acceder']);

        $this->comoEmpresa($tobogan);
        $cajeroTobogan = Role::create(['name' => 'Cajero', 'guard_name' => 'web', 'empresa_id' => $tobogan->id]);
        $cajeroTobogan->syncPermissions(['pos.acceder', 'ventas.ver']);

        $this->assertNotEquals($cajeroA->id, $cajeroTobogan->id);
        $this->assertEquals(['pos.acceder'], $cajeroA->permissions->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['pos.acceder', 'ventas.ver'], $cajeroTobogan->permissions->pluck('name')->all());
    }

    /** 3. Un usuario con rol Cajero (solo pos.acceder) ve el POS pero no Roles ni nada de Tobogán. */
    public function test_3_usuario_con_rol_cajero_solo_ve_el_pos(): void
    {
        $empresaA = $this->crearEmpresa('Empresa A', '131000001');
        $tobogan = $this->crearEmpresa('Tobogán', '131000002');

        $this->comoEmpresa($empresaA);
        $cajeroA = Role::create(['name' => 'Cajero', 'guard_name' => 'web', 'empresa_id' => $empresaA->id]);
        $cajeroA->syncPermissions(['pos.acceder']);

        $cajero = User::factory()->create(['empresa_id' => $empresaA->id]);
        $cajero->unsetRelation('roles')->unsetRelation('permissions');
        $cajero->assignRole('Cajero');

        $this->comoEmpresa($empresaA);

        // Tiene acceso al POS (permiso pos.acceder) y nada más de lo que traía el rol base.
        // No se renderiza la página completa: PuntoDeVenta consulta ArqueoCaja, que arrastra un
        // hueco de tenancy preexistente y no relacionado con roles (ver informe final).
        $this->assertTrue($cajero->can('pos.acceder'));
        $this->assertFalse($cajero->can('ventas.ver'));
        $this->assertFalse($cajero->can('productos.crear'));

        // No ve Roles (sin permiso roles.gestionar).
        $this->actingAs($cajero)
            ->get(RoleResource::getUrl('index', tenant: $empresaA))
            ->assertForbidden();

        // No entra a Tobogán por URL.
        $this->actingAs($cajero)
            ->get(ProductoResource::getUrl('index', tenant: $tobogan))
            ->assertNotFound();
    }

    /** 4. El listado de roles de Empresa A no muestra los roles de Tobogán. */
    public function test_4_roleresource_de_empresa_a_no_muestra_roles_de_tobogan(): void
    {
        $empresaA = $this->crearEmpresa('Empresa A', '131000001');
        $tobogan = $this->crearEmpresa('Tobogán', '131000002');

        $this->comoEmpresa($tobogan);
        $cajeroTobogan = Role::create(['name' => 'Cajero Tobogán Exclusivo', 'guard_name' => 'web', 'empresa_id' => $tobogan->id]);
        $cajeroTobogan->syncPermissions(['pos.acceder']);

        $this->comoEmpresa($empresaA);
        $adminA = User::factory()->create(['empresa_id' => $empresaA->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($adminA, $empresaA);

        $this->comoEmpresa($empresaA);
        $this->actingAs($adminA)
            ->get(RoleResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee('Administrador')
            ->assertSee('Vendedor')
            ->assertSee('Almacenista')
            ->assertDontSee('Cajero Tobogán Exclusivo');
    }

    /**
     * 5. El super-admin, al cambiar de empresa con el switcher, ve los roles de la empresa
     * activa cada vez — no arrastra los de la anterior.
     */
    public function test_5_super_admin_recalcula_roles_al_cambiar_de_empresa(): void
    {
        $empresaA = $this->crearEmpresa('Empresa A', '131000001');
        $tobogan = $this->crearEmpresa('Tobogán', '131000002');

        $this->comoEmpresa($tobogan);
        $cajeroTobogan = Role::create(['name' => 'Cajero Tobogán Exclusivo', 'guard_name' => 'web', 'empresa_id' => $tobogan->id]);
        $cajeroTobogan->syncPermissions(['pos.acceder']);

        $superAdmin = User::factory()->create(['empresa_id' => null, 'es_super_admin' => true]);

        // Entra a Empresa A: ve sus 3 roles base, NO el Cajero de Tobogán.
        $this->comoEmpresa($empresaA);
        $this->actingAs($superAdmin)
            ->get(RoleResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee('Administrador')
            ->assertDontSee('Cajero Tobogán Exclusivo');

        // Cambia a Tobogán (switcher): ahora SÍ ve el Cajero de Tobogán.
        $this->comoEmpresa($tobogan);
        $this->actingAs($superAdmin)
            ->get(RoleResource::getUrl('index', tenant: $tobogan))
            ->assertOk()
            ->assertSee('Cajero Tobogán Exclusivo');
    }

    /**
     * 6. No hay 404 inesperados en el camino normal (señal de que
     * EstablecerEmpresaPermisos quedó antes de SubstituteBindings/Authenticate, no después).
     */
    public function test_6_no_hay_404_inesperados_en_el_flujo_normal(): void
    {
        $empresaA = $this->crearEmpresa('Empresa A', '131000001');
        $this->comoEmpresa($empresaA);

        $admin = User::factory()->create(['empresa_id' => $empresaA->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresaA);

        $this->comoEmpresa($empresaA);

        $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/'.$empresaA->slug);
        $this->actingAs($admin)->get(RoleResource::getUrl('index', tenant: $empresaA))->assertOk();
        $this->actingAs($admin)->get(ProductoResource::getUrl('index', tenant: $empresaA))->assertOk();

        // Crear un rol vía el panel (Livewire), de punta a punta, tampoco debe fallar.
        Livewire::actingAs($admin)
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'Supervisor',
                'permisos' => ['Ventas' => ['pos.acceder', 'ventas.ver']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('roles', ['name' => 'Supervisor', 'empresa_id' => $empresaA->id]);
    }
}
