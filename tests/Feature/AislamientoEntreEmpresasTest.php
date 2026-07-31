<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\ArqueoCajaResource;
use App\Filament\Resources\ClienteResource;
use App\Filament\Resources\DescuentoResource;
use App\Filament\Resources\DescuentoResource\Pages\CreateDescuento;
use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\PedidoCompraResource;
use App\Filament\Resources\ProductoResource;
use App\Filament\Resources\ProductoResource\Pages\CreateProducto;
use App\Models\Cliente;
use App\Models\Descuento;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\ArqueoCajaService;
use App\Services\PedidoCompraService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
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

        // Los roles son por empresa (roles-per-empresa, T2): esta empresa nace DESPUÉS del
        // seed() de arriba, así que RolePermissionSeeder no llegó a sembrarle los suyos.
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);

        $admin = User::factory()->create(['empresa_id' => $empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresa);

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

        $proveedor = Proveedor::create([
            'empresa_id' => $empresa->id,
            'rnc' => '02'.$empresa->id.'2222222',
            'tipo' => TipoProveedor::FORMAL,
            'nombre' => "Proveedor de {$razonSocial}",
            'activo' => true,
        ]);

        return compact('empresa', 'admin', 'producto', 'cliente', 'proveedor');
    }

    /**
     * Sincroniza el tenant de Filament Y el contexto de permisos de spatie a $empresa antes de
     * actuar como uno de sus usuarios. Hace falta explícitamente en este archivo (no en la
     * mayoría de los tests) porque es el único que crea varias empresas y alterna entre ellas
     * dentro del mismo test: Role ahora también lleva el scoping automático de Filament (ver
     * RoleResource, PASO 5), así que si Filament::getTenant() se queda "atrás" (apuntando a la
     * empresa por defecto de TestCase, o a la última empresa creada) mientras
     * setPermissionsTeamId() ya apunta a la correcta, ambos scopes se contradicen y las
     * consultas de roles/permisos no encuentran nada. En producción esto no pasa: cada request
     * arranca con Filament::getTenant() en null y IdentifyTenant lo resuelve una sola vez.
     */
    private function comoEmpresa(Empresa $empresa): void
    {
        Filament::setTenant($empresa, isQuiet: true);
        setPermissionsTeamId($empresa->id);
    }

    /** 1. Login como usuario de Empresa A: entra DIRECTO a su empresa, sin selector. */
    public function test_1_usuario_de_una_sola_empresa_entra_directo_sin_selector(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        $this->comoEmpresa($empresaA);

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
        $this->comoEmpresa($empresaA);

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

        $this->comoEmpresa($empresaA);
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

        $this->comoEmpresa($empresaA);

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
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');
        $this->comoEmpresa($empresaA);

        $this->actingAs($adminA)
            ->get(ProductoResource::getUrl('index', tenant: $empresaTobogan))
            ->assertNotFound();
    }

    /** EmpresaResource no es accesible para un administrador normal (solo super-admin). */
    public function test_7_administrador_normal_no_ve_empresa_resource(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        $this->comoEmpresa($empresaA);

        $this->actingAs($adminA)
            ->get(EmpresaResource::getUrl('index', tenant: $empresaA))
            ->assertForbidden();
    }

    /**
     * 8. ArqueoCaja y PedidoCompra (hueco de tenancy alineado en este cambio): cada empresa solo
     * ve sus propios arqueos de caja y pedidos de compra en el índice, nunca los de la otra.
     */
    public function test_8_cada_empresa_solo_ve_sus_propios_arqueos_y_pedidos_de_compra(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA, 'proveedor' => $proveedorA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'admin' => $adminTobogan, 'producto' => $productoTobogan, 'proveedor' => $proveedorTobogan] =
            $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaA);
        app(ArqueoCajaService::class)->abrir('500.00', $adminA->id, $empresaA);
        app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedorA->id,
            'fecha' => now(),
            'notas' => null,
            'lineas' => [['producto_id' => $productoA->id, 'cantidad' => 1, 'costo_unitario' => 50]],
        ], $adminA->id, $empresaA);

        $this->comoEmpresa($empresaTobogan);
        app(ArqueoCajaService::class)->abrir('700.00', $adminTobogan->id, $empresaTobogan);
        app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedorTobogan->id,
            'fecha' => now(),
            'notas' => null,
            'lineas' => [['producto_id' => $productoTobogan->id, 'cantidad' => 1, 'costo_unitario' => 50]],
        ], $adminTobogan->id, $empresaTobogan);

        $this->comoEmpresa($empresaA);

        $this->actingAs($adminA)
            ->get(ArqueoCajaResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee($adminA->name)
            ->assertDontSee($adminTobogan->name);

        $this->actingAs($adminA)
            ->get(PedidoCompraResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee($proveedorA->nombre)
            ->assertDontSee($proveedorTobogan->nombre);
    }

    /** 9. El PDF de un arqueo o pedido de OTRA empresa se rechaza, aunque el usuario tenga el permiso. */
    public function test_9_no_puede_descargar_pdf_de_arqueo_o_pedido_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'admin' => $adminTobogan, 'producto' => $productoTobogan, 'proveedor' => $proveedorTobogan] =
            $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaTobogan);
        $arqueoTobogan = app(ArqueoCajaService::class)->abrir('500.00', $adminTobogan->id, $empresaTobogan);
        $arqueoTobogan = app(ArqueoCajaService::class)->cerrar($arqueoTobogan, '500.00', null, $adminTobogan->id);
        $pedidoTobogan = app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedorTobogan->id,
            'fecha' => now(),
            'notas' => null,
            'lineas' => [['producto_id' => $productoTobogan->id, 'cantidad' => 1, 'costo_unitario' => 50]],
        ], $adminTobogan->id, $empresaTobogan);

        $this->comoEmpresa($empresaA);

        $this->actingAs($adminA)
            ->get(route('arqueos-caja.pdf', $arqueoTobogan))
            ->assertForbidden();

        $this->actingAs($adminA)
            ->get(route('pedidos-compra.pdf', $pedidoTobogan))
            ->assertForbidden();
    }

    /** 10. Abrir un arqueo y crear un pedido de compra los asocia automáticamente a la empresa actual. */
    public function test_10_abrir_arqueo_y_crear_pedido_los_asocia_a_la_empresa_actual(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA, 'proveedor' => $proveedorA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        $this->comoEmpresa($empresaA);

        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $adminA->id, $empresaA);
        $pedido = app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedorA->id,
            'fecha' => now(),
            'notas' => null,
            'lineas' => [['producto_id' => $productoA->id, 'cantidad' => 1, 'costo_unitario' => 50]],
        ], $adminA->id, $empresaA);

        $this->assertDatabaseHas('arqueos_caja', ['id' => $arqueo->id, 'empresa_id' => $empresaA->id]);
        $this->assertDatabaseHas('pedidos_compra', ['id' => $pedido->id, 'empresa_id' => $empresaA->id]);
    }

    /**
     * 11. No se puede crear un pedido de compra con un proveedor o un producto de OTRA empresa,
     * ni siquiera manipulando directamente los ids enviados al service (el Select ya los filtra,
     * pero el service es la segunda capa: nunca confía en que el id recibido sea de la empresa
     * activa).
     */
    public function test_11_no_se_puede_crear_un_pedido_con_proveedor_o_producto_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['proveedor' => $proveedorTobogan, 'producto' => $productoTobogan] =
            $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaA);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El proveedor indicado no existe o no pertenece a esta empresa.');

        app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedorTobogan->id,
            'fecha' => now(),
            'notas' => null,
            'lineas' => [['producto_id' => $productoA->id, 'cantidad' => 1, 'costo_unitario' => 50]],
        ], $adminA->id, $empresaA);
    }

    /** 11b. Mismo caso pero con un producto ajeno (proveedor propio, línea con producto de otra empresa). */
    public function test_11b_no_se_puede_crear_un_pedido_con_producto_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'proveedor' => $proveedorA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['producto' => $productoTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaA);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Uno o más productos del pedido no existen o no pertenecen a esta empresa.');

        app(PedidoCompraService::class)->crear([
            'proveedor_id' => $proveedorA->id,
            'fecha' => now(),
            'notas' => null,
            'lineas' => [['producto_id' => $productoTobogan->id, 'cantidad' => 1, 'costo_unitario' => 50]],
        ], $adminA->id, $empresaA);
    }

    /**
     * 12. Mantenimiento de Descuentos (Caja/Facturación, T-descuentos): cada empresa solo ve sus
     * propios descuentos configurados en el índice, y crear uno lo asocia a la empresa activa.
     */
    public function test_12_cada_empresa_solo_ve_sus_propios_descuentos_y_crearlos_los_asocia_a_la_actual(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'admin' => $adminTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaTobogan);
        $descuentoTobogan = Descuento::create([
            'empresa_id' => $empresaTobogan->id,
            'nombre' => 'Descuento de Tobogán',
            'porcentaje' => 15,
            'activo' => true,
        ]);

        $this->comoEmpresa($empresaA);
        $descuentoA = Descuento::create([
            'empresa_id' => $empresaA->id,
            'nombre' => 'Descuento de Empresa A',
            'porcentaje' => 10,
            'activo' => true,
        ]);

        $this->actingAs($adminA)
            ->get(DescuentoResource::getUrl('index', tenant: $empresaA))
            ->assertOk()
            ->assertSee($descuentoA->nombre)
            ->assertDontSee($descuentoTobogan->nombre);

        TenantDefaults::reiniciar($empresaA);

        Livewire::actingAs($adminA)
            ->test(CreateDescuento::class)
            ->fillForm(['nombre' => 'Nuevo descuento', 'porcentaje' => 20, 'activo' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('descuentos', ['nombre' => 'Nuevo descuento', 'empresa_id' => $empresaA->id]);
    }
}
