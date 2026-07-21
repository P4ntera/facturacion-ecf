<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\ClienteResource;
use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\ProductoResource;
use App\Filament\Resources\ProductoResource\Pages\CreateProducto;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TenantDefaults;
use Tests\TestCase;

/**
 * Verificación obligatoria de aislamiento entre empresas (PASO 7 del prompt de multi-tenancy).
 * Si cualquiera de estos falla, el tenancy no está listo, sin importar que el resto del
 * checklist esté implementado.
 */
class AislamientoEntreEmpresasTest extends TestCase
{
    use RefreshDatabase;

    private function crearEmpresaConDatos(string $razonSocial, string $rnc): array
    {
        $this->seed(RolePermissionSeeder::class);

        $empresa = Empresa::create(['razon_social' => $razonSocial, 'rnc' => $rnc]);

        $admin = User::factory()->create(['empresa_id' => $empresa->id]);
        $admin->assignRole('Administrador');

        $producto = Producto::create([
            'empresa_id' => $empresa->id,
            'codigo' => 'COD-'.$empresa->id,
            'nombre' => "Producto de {$razonSocial}",
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $cliente = Cliente::create([
            'empresa_id' => $empresa->id,
            'tipo_documento' => TipoDocumentoCliente::CEDULA,
            'documento' => '00'.$empresa->id.'11111111',
            'nombre' => "Cliente de {$razonSocial}",
            'activo' => true,
        ]);

        return compact('empresa', 'admin', 'producto', 'cliente');
    }

    /** 1. Login como usuario de Empresa A: entra DIRECTO a su empresa, sin selector. */
    public function test_1_usuario_de_una_sola_empresa_entra_directo_sin_selector(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');

        $this->actingAs($adminA)
            ->get('/admin')
            ->assertRedirect('/admin/'.$empresaA->slug);
    }

    /** 1 y 2. Cada empresa ve solo sus propios productos y clientes; no ve nada de la otra. */
    public function test_2_cada_empresa_solo_ve_sus_propios_productos_y_clientes(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA, 'cliente' => $clienteA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['producto' => $productoTobogan, 'cliente' => $clienteTobogan] =
            $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->actingAs($adminA)
            ->get(ProductoResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee($productoA->nombre)
            ->assertDontSee($productoTobogan->nombre);

        $this->actingAs($adminA)
            ->get(ClienteResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee($clienteA->nombre)
            ->assertDontSee($clienteTobogan->nombre);
    }

    /** 3. Crear un producto como Empresa A queda con su empresa_id automáticamente. */
    public function test_3_crear_producto_lo_asocia_automaticamente_a_la_empresa_actual(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');

        Filament::setTenant($empresaA, isQuiet: true);
        TenantDefaults::reiniciar($empresaA);

        Livewire::actingAs($adminA)
            ->test(CreateProducto::class)
            ->fillForm([
                'codigo' => 'NUEVO-1',
                'nombre' => 'Producto recién creado',
                'tipo' => TipoProducto::PRODUCTO->value,
                'precio' => 50,
                'costo' => 25,
                'tasa_itbis' => TasaItbis::DIECIOCHO->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('productos', [
            'codigo' => 'NUEVO-1',
            'empresa_id' => $empresaA->id,
        ]);
    }

    /** 4. En el POS de Empresa A, los resultados de búsqueda solo traen productos/clientes de A. */
    public function test_4_el_pos_solo_sugiere_productos_y_clientes_de_la_empresa_actual(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA, 'cliente' => $clienteA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['producto' => $productoTobogan, 'cliente' => $clienteTobogan] =
            $this->crearEmpresaConDatos('Tobogán', '131000002');

        Filament::setTenant($empresaA, isQuiet: true);

        $componente = Livewire::actingAs($adminA)
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'Producto de')
            ->set('busquedaCliente', 'Cliente de');

        $productosSugeridos = $componente->instance()->productosSugeridos()->pluck('nombre');
        $clientesSugeridos = $componente->instance()->clientesSugeridos()->pluck('nombre');

        $this->assertTrue($productosSugeridos->contains($productoA->nombre));
        $this->assertFalse($productosSugeridos->contains($productoTobogan->nombre));

        $this->assertTrue($clientesSugeridos->contains($clienteA->nombre));
        $this->assertFalse($clientesSugeridos->contains($clienteTobogan->nombre));
    }

    /** 5. El super-admin ve el selector de empresas, puede entrar a cualquiera y ve "Empresas". */
    public function test_5_el_super_admin_ve_el_selector_y_accede_a_empresas(): void
    {
        ['empresa' => $empresaA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $superAdmin = User::factory()->create(['empresa_id' => null, 'es_super_admin' => true]);
        $superAdmin->assignRole('Administrador');

        // No es igualdad estricta: TestCase crea una empresa "de fondo" por test (ver
        // Tests\TestCase), también activa, que el super-admin legítimamente también vería.
        $tenants = $superAdmin->getTenants(Filament::getPanel('admin'))->pluck('id');
        $this->assertTrue($tenants->contains($empresaA->id));
        $this->assertTrue($tenants->contains($empresaTobogan->id));

        $this->assertTrue($superAdmin->canAccessTenant($empresaA));
        $this->assertTrue($superAdmin->canAccessTenant($empresaTobogan));

        $this->actingAs($superAdmin)
            ->get(ProductoResource::getUrl('index', tenant: $empresaTobogan))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(EmpresaResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee($empresaA->razon_social)
            ->assertSee($empresaTobogan->razon_social);
    }

    /**
     * 6. Un usuario normal no puede entrar por URL a una empresa que no es la suya. Filament
     * responde 404 (no 403) a propósito, para no confirmarle a quien no pertenece que el slug
     * de esa empresa existe (ver Filament\Http\Middleware\IdentifyTenant).
     */
    public function test_6_no_puede_acceder_por_url_a_una_empresa_ajena(): void
    {
        ['admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->actingAs($adminA)
            ->get(ProductoResource::getUrl('index', tenant: $empresaTobogan))
            ->assertNotFound();
    }

    /** EmpresaResource no es accesible para un administrador normal (solo super-admin). */
    public function test_7_administrador_normal_no_ve_empresa_resource(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');

        $this->actingAs($adminA)
            ->get(EmpresaResource::getUrl('index', tenant: $empresaA))
            ->assertForbidden();
    }
}
