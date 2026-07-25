<?php

namespace Tests\Feature;

use App\Enums\Modulo;
use App\Exceptions\DependenciaModuloException;
use App\Filament\Resources\ClienteResource;
use App\Filament\Resources\DocumentoRecibidoResource;
use App\Filament\Resources\SecuenciaNcfResource;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Role;
use App\Models\User;
use App\Services\ModulosEmpresaService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Checklist de verificación de T4 (módulos por empresa): aislamiento del toggle entre empresas,
 * reactivación sin pérdida de datos, combinación módulo+permiso, coherencia con usa_ecf (T3) y
 * dependencias entre módulos. El resto de la suite (T1 aislamiento, T2 roles, T3 config) se
 * verifica en el mismo `artisan test` — ver el reporte final, no hay nada que duplicar aquí.
 */
class VerificacionModulosPorEmpresaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Catálogo de permisos (global): necesario antes de poder sincronizar roles en
        // cualquier empresa que se cree dentro de un test.
        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array{0: Empresa, 1: User} */
    private function empresaConAdmin(string $razonSocial): array
    {
        $empresa = Empresa::factory()->create(['razon_social' => $razonSocial]);
        app(ModulosEmpresaService::class)->sembrarModulos($empresa);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);

        $admin = User::factory()->create(['empresa_id' => $empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresa);

        return [$empresa, $admin];
    }

    /**
     * Dos artefactos de reutilizar el mismo proceso PHP entre requests simuladas (nunca pasa en
     * producción, donde cada request arranca en limpio) que hay que limpiar a mano al cambiar de
     * empresa/usuario dentro de un mismo test:
     *
     * 1. Filament::getTenant() puede seguir apuntando al tenant de un test/setUp anterior hasta
     *    que IdentifyTenant lo resuelva de la URL — y eso pasa DESPUÉS del canAccessPanel() del
     *    login (ver Authenticate de Filament), que ya consulta modelos con scoping automático
     *    por tenant (p. ej. Role, vía RoleResource).
     * 2. El caché interno de PermissionRegistrar y las relaciones roles/permissions ya cargadas
     *    en el objeto User quedan del team anterior; EstablecerEmpresaPermisos y el listener de
     *    TenantSet las limpian en un request real, pero no alcanzan a hacerlo antes de que
     *    Filament vuelva a llamar canAccess() varias veces (menú + ruta) en la misma request.
     *
     * Sin esto, un usuario de una empresa distinta a la última usada en el proceso puede ver sus
     * propios permisos como "vacíos" y recibir un 403 que nada tiene que ver con módulos.
     */
    private function visitar(User $usuario, Empresa $empresa, string $url): TestResponse
    {
        Filament::setTenant($empresa, isQuiet: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $usuario->unsetRelation('roles')->unsetRelation('permissions');

        return $this->actingAs($usuario)->get($url);
    }

    /** 1. Desactivar un módulo lo oculta SOLO para esa empresa (menú y acceso por URL). */
    public function test_1_desactivar_un_modulo_lo_oculta_solo_para_esa_empresa(): void
    {
        [$tobogan, $adminTobogan] = $this->empresaConAdmin('Tobogán Diversiones SRL');
        [$empresaA, $adminA] = $this->empresaConAdmin('Empresa A SRL');

        app(ModulosEmpresaService::class)->actualizarModulos($tobogan, [
            Modulo::MAESTROS_CLIENTES->value => false,
        ]);

        $this->visitar($adminTobogan, $tobogan, ClienteResource::getUrl('index', tenant: $tobogan))
            ->assertForbidden();

        // La otra empresa nunca tocó su configuración: sigue viendo Clientes con normalidad.
        $this->visitar($adminA, $empresaA, ClienteResource::getUrl('index', tenant: $empresaA))
            ->assertOk();
    }

    /** 2. Reactivar el módulo lo muestra de nuevo, con los datos que ya existían intactos. */
    public function test_2_reactivar_un_modulo_lo_muestra_de_nuevo_con_los_datos_intactos(): void
    {
        [$tobogan, $adminTobogan] = $this->empresaConAdmin('Tobogán Diversiones SRL');

        $cliente = Cliente::create([
            'empresa_id' => $tobogan->id,
            'nombre' => 'Cliente de Tobogán',
            'activo' => true,
        ]);

        app(ModulosEmpresaService::class)->actualizarModulos($tobogan, [
            Modulo::MAESTROS_CLIENTES->value => false,
        ]);
        $this->visitar($adminTobogan, $tobogan, ClienteResource::getUrl('index', tenant: $tobogan))
            ->assertForbidden();

        app(ModulosEmpresaService::class)->actualizarModulos($tobogan, [
            Modulo::MAESTROS_CLIENTES->value => true,
        ]);
        $this->visitar($adminTobogan, $tobogan, ClienteResource::getUrl('index', tenant: $tobogan))
            ->assertOk();

        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'nombre' => 'Cliente de Tobogán']);
    }

    /**
     * 3. Módulo y permiso se combinan con AND: sin permiso no se ve aunque el módulo esté
     * habilitado; con permiso tampoco se ve si el módulo está deshabilitado.
     */
    public function test_3_modulo_y_permiso_se_combinan_con_and(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin('Empresa Combinación SRL');

        setPermissionsTeamId($empresa->id);
        $rolSinClientes = Role::firstOrCreate([
            'empresa_id' => $empresa->id,
            'name' => 'SinClientes',
            'guard_name' => 'web',
        ]);
        $rolSinClientes->syncPermissions(['pos.acceder']);

        $usuarioSinPermiso = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuarioSinPermiso->assignRole($rolSinClientes);

        // Módulo habilitado, SIN el permiso 'clientes.ver': no debe verlo.
        $this->visitar($usuarioSinPermiso, $empresa, ClienteResource::getUrl('index', tenant: $empresa))
            ->assertForbidden();

        // CON el permiso (admin tiene todos), módulo deshabilitado: tampoco debe verlo.
        app(ModulosEmpresaService::class)->actualizarModulos($empresa, [
            Modulo::MAESTROS_CLIENTES->value => false,
        ]);
        $this->visitar($admin, $empresa, ClienteResource::getUrl('index', tenant: $empresa))
            ->assertForbidden();
    }

    /** 4. usa_ecf=false oculta los módulos ECF_* aunque sus toggles sigan encendidos. */
    public function test_4_usa_ecf_false_oculta_los_modulos_ecf_pese_al_toggle_encendido(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin('Empresa e-CF SRL');

        // Los toggles de empresa_modulos siguen en su default (habilitado=true): lo único que
        // cambia es usa_ecf.
        $empresa->update(['usa_ecf' => false]);

        $this->visitar($admin, $empresa, SecuenciaNcfResource::getUrl('index', tenant: $empresa))
            ->assertForbidden();

        $this->visitar($admin, $empresa, DocumentoRecibidoResource::getUrl('index', tenant: $empresa))
            ->assertForbidden();
    }

    /** 5. Intentar desactivar Productos con POS activo se bloquea con un mensaje claro. */
    public function test_5_no_se_puede_desactivar_productos_con_pos_activo(): void
    {
        [$empresa] = $this->empresaConAdmin('Empresa Dependencias SRL');

        $this->expectException(DependenciaModuloException::class);
        $this->expectExceptionMessage('No se puede desactivar «Productos»: «Punto de Venta» lo necesita para funcionar.');

        app(ModulosEmpresaService::class)->actualizarModulos($empresa, [
            Modulo::MAESTROS_PRODUCTOS->value => false,
        ]);

        $this->assertTrue($empresa->fresh()->tieneModulo(Modulo::MAESTROS_PRODUCTOS));
    }

    /** Desactivar ambos a la vez (POS y Productos) sí se permite: ya no hay dependencia rota. */
    public function test_5b_desactivar_ambos_modulos_juntos_si_se_permite(): void
    {
        [$empresa] = $this->empresaConAdmin('Empresa Dependencias Conjuntas SRL');

        app(ModulosEmpresaService::class)->actualizarModulos($empresa, [
            Modulo::MAESTROS_PRODUCTOS->value => false,
            Modulo::VENTAS_POS->value => false,
        ]);

        $empresa = $empresa->fresh();
        $this->assertFalse($empresa->tieneModulo(Modulo::MAESTROS_PRODUCTOS));
        $this->assertFalse($empresa->tieneModulo(Modulo::VENTAS_POS));
    }
}
