<?php

namespace Tests\Feature;

use App\Filament\Resources\ClienteResource;
use App\Filament\Resources\ProductoResource;
use App\Filament\Resources\ProveedorResource;
use App\Http\Middleware\EstablecerEmpresaPermisos;
use App\Models\Empresa;
use App\Models\User;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

/**
 * Verifica que el contexto de empresa (setPermissionsTeamId) sobreviva a la navegación DENTRO
 * del panel, no solo al login. El bug real: Livewire enruta cualquier interacción de un
 * componente ya montado (tablas, paginación, wire:navigate en algunos casos, acciones) por
 * /livewire/update, un mecanismo propio (Livewire\Mechanisms\PersistentMiddleware) que NO
 * reaplica la pipeline completa de la request original — solo los middleware marcados
 * explícitamente isPersistent: true. EstablecerEmpresaPermisos corría bien en la carga inicial
 * (confirmado con route:list --json desde el T2) pero nunca se había marcado persistente, así
 * que cualquier interacción posterior perdía el team activo y veía "cero permisos".
 */
class ContextoEmpresaEnNavegacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function comoEmpresa(Empresa $empresa): void
    {
        Filament::setTenant($empresa, isQuiet: true);
        setPermissionsTeamId($empresa->id);
    }

    /**
     * La prueba definitiva del fix: EstablecerEmpresaPermisos debe estar en la lista de
     * middleware persistente de Livewire, la MISMA lista que usa Filament para sus propios
     * middleware críticos (Authenticate, IdentifyTenant, SetUpPanel — ver
     * FilamentServiceProvider::boot()). Sin esto, /livewire/update nunca lo ejecuta.
     */
    public function test_establecer_empresa_permisos_esta_registrado_como_middleware_persistente(): void
    {
        $persistentes = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(EstablecerEmpresaPermisos::class, $persistentes);
    }

    /**
     * Replica el filtro EXACTO que Livewire aplica en
     * PersistentMiddleware::filterMiddlewareByPersistentMiddleware() para una petición a
     * /livewire/update de un componente originalmente montado en una ruta del panel: el
     * middleware de la ruta, cruzado con la lista persistente. Si EstablecerEmpresaPermisos no
     * sobrevive este filtro, una interacción Livewire dentro de esa página pierde el contexto de
     * empresa aunque la carga inicial haya funcionado.
     */
    public function test_el_middleware_de_contexto_sobrevive_al_filtro_de_livewire_para_una_ruta_del_panel(): void
    {
        $ruta = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === 'admin/{tenant}/clientes');
        $this->assertNotNull($ruta, 'No se encontró la ruta admin/{tenant}/clientes.');

        $middlewareDeLaRuta = app('router')->gatherRouteMiddleware($ruta);
        $persistentes = collect(app(PersistentMiddleware::class)->getPersistentMiddleware());

        $sobrevive = collect($middlewareDeLaRuta)->contains(
            fn ($m) => is_string($m) && $persistentes->contains(Str::before($m, ':'))
                && Str::before($m, ':') === EstablecerEmpresaPermisos::class
        );

        $this->assertTrue($sobrevive, 'EstablecerEmpresaPermisos no sobrevive al filtro de middleware persistente de Livewire.');
    }

    /** 1. Administrador de una empresa (no la "de fondo" del TestCase) ve todos los módulos de su rol. */
    public function test_1_administrador_ve_sus_modulos_tras_login(): void
    {
        $empresa = Empresa::create(['razon_social' => 'Tobogán Diversiones SRL', 'rnc' => '131700001', 'activa' => true]);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);

        $admin = User::factory()->create(['empresa_id' => $empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresa);

        $this->comoEmpresa($empresa);

        $this->actingAs($admin)
            ->get(ClienteResource::getUrl('index', tenant: $empresa))
            ->assertOk();

        $this->actingAs($admin)
            ->get(ProveedorResource::getUrl('index', tenant: $empresa))
            ->assertOk();
    }

    /** 2. Un Vendedor ve solo lo que su rol permite (Clientes/Productos sí, Proveedores no). */
    public function test_2_vendedor_ve_solo_lo_de_su_rol(): void
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa Vendedor SRL', 'rnc' => '131700002', 'activa' => true]);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);

        $vendedor = User::factory()->create(['empresa_id' => $empresa->id]);
        $this->comoEmpresa($empresa);
        $vendedor->assignRole('Vendedor');

        $this->actingAs($vendedor)
            ->get(ProductoResource::getUrl('index', tenant: $empresa))
            ->assertOk();

        $this->actingAs($vendedor)
            ->get(ClienteResource::getUrl('index', tenant: $empresa))
            ->assertOk();

        // Vendedor no tiene proveedores.ver: el índice de Proveedores le queda vedado.
        $this->actingAs($vendedor)
            ->get(ProveedorResource::getUrl('index', tenant: $empresa))
            ->assertForbidden();
    }

    /** 3. Super-admin cambia de empresa activa: los permisos se recalculan para la nueva empresa. */
    public function test_3_super_admin_recalcula_permisos_al_cambiar_de_empresa(): void
    {
        $empresaA = Empresa::create(['razon_social' => 'Empresa A SRL', 'rnc' => '131700003', 'activa' => true]);
        $empresaB = Empresa::create(['razon_social' => 'Empresa B SRL', 'rnc' => '131700004', 'activa' => true]);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresaA);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresaB);

        $superAdmin = User::factory()->create(['empresa_id' => null, 'es_super_admin' => true]);

        $this->actingAs($superAdmin)
            ->get(ClienteResource::getUrl('index', tenant: $empresaA))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(ClienteResource::getUrl('index', tenant: $empresaB))
            ->assertOk();
    }

    /** 4. El aislamiento entre empresas (T1) sigue intacto: un Vendedor de A no entra a B por URL. */
    public function test_4_aislamiento_entre_empresas_sigue_intacto(): void
    {
        $empresaA = Empresa::create(['razon_social' => 'Empresa A SRL', 'rnc' => '131700005', 'activa' => true]);
        $empresaB = Empresa::create(['razon_social' => 'Empresa B SRL', 'rnc' => '131700006', 'activa' => true]);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresaA);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresaB);

        $vendedorA = User::factory()->create(['empresa_id' => $empresaA->id]);
        $this->comoEmpresa($empresaA);
        $vendedorA->assignRole('Vendedor');

        $this->actingAs($vendedorA)
            ->get(ClienteResource::getUrl('index', tenant: $empresaB))
            ->assertNotFound();
    }
}
