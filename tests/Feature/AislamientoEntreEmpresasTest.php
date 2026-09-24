<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Exceptions\VentaInvalidaException;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\ArqueoCajaResource;
use App\Filament\Resources\ClienteResource;
use App\Filament\Resources\DescuentoResource;
use App\Filament\Resources\DescuentoResource\Pages\CreateDescuento;
use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\PedidoCompraResource;
use App\Filament\Resources\ProductoResource;
use App\Filament\Resources\ProductoResource\Pages\CreateProducto;
use App\Filament\Resources\ProductoResource\Pages\ListProductos;
use App\Models\Cliente;
use App\Models\Descuento;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\ArqueoCajaService;
use App\Services\CompraService;
use App\Services\DevolucionCompraService;
use App\Services\PedidoCompraService;
use App\Services\RolesEmpresaService;
use App\Services\SecuenciaNcfService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $resultadosBusqueda = $componente->instance()->resultadosBusqueda()->pluck('etiqueta');
        $clientesSugeridos = $componente->instance()->clientesSugeridos()->pluck('nombre');

        $this->assertTrue($resultadosBusqueda->contains($productoA->nombre));
        $this->assertFalse($resultadosBusqueda->contains($productoTobogan->nombre));

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

    /** 13. El POS de Empresa A no encuentra (ni agrega) una presentación de un producto de OTRA empresa. */
    public function test_13_el_pos_no_escanea_presentaciones_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'producto' => $productoTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $productoTobogan->presentaciones()->create([
            'empresa_id' => $empresaTobogan->id,
            'nombre' => 'Caja',
            'factor' => 24,
            'codigo_barra' => '999999',
            'precio' => 500,
            'es_base' => false,
            'activa' => true,
        ]);

        $this->comoEmpresa($empresaA);

        Livewire::actingAs($adminA)
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '999999')
            ->call('escanearOBuscar')
            ->assertSet('carrito', []);
    }

    /**
     * 13b. VentaService::registrar() rechaza una presentación que no pertenece al producto de la
     * línea, incluso si ambos ids existen (uno de la empresa activa, la presentación de otra):
     * la revalidación es por producto_id, así que ninguna combinación cruzada pasa.
     */
    public function test_13b_no_se_puede_vender_la_presentacion_de_un_producto_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA, 'cliente' => $clienteA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'producto' => $productoTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        // usa_ecf tiene default true a nivel de BD, pero Empresa::create() no lo refleja en el
        // modelo en memoria (mismo gotcha de siempre con columnas con default en Postgres):
        // refresh() lo trae de vuelta antes de que VentaService lo necesite.
        $empresaA->refresh();

        $presentacionTobogan = $productoTobogan->presentaciones()->create([
            'empresa_id' => $empresaTobogan->id,
            'nombre' => 'Caja',
            'factor' => 24,
            'codigo_barra' => '999998',
            'precio' => 500,
            'es_base' => false,
            'activa' => true,
        ]);

        $this->comoEmpresa($empresaA);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $clienteA->id,
            'lineas' => [['producto_id' => $productoA->id, 'presentacion_id' => $presentacionTobogan->id, 'cantidad' => 1]],
        ], $empresaA);
    }

    /** 14. La búsqueda de la tabla de productos de Empresa A no encuentra por el código de barras de una presentación de OTRA empresa. */
    public function test_14_la_busqueda_de_productos_no_encuentra_presentaciones_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'producto' => $productoTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $productoTobogan->presentaciones()->create([
            'empresa_id' => $empresaTobogan->id,
            'nombre' => 'Caja',
            'factor' => 24,
            'codigo_barra' => '888888',
            'precio' => 500,
            'es_base' => false,
            'activa' => true,
        ]);

        $this->comoEmpresa($empresaA);

        // El global scope de tenancy de Filament sobre Producto se registra la primera vez que
        // una request real pasa por el middleware del panel (IdentifyTenant); Livewire::test()
        // no lo dispara por sí solo (ver el comentario de comoEmpresa() más arriba sobre el
        // mismo tipo de gap). Un GET real "calienta" el registro antes de usar Livewire::test()
        // para la aserción de búsqueda.
        $this->actingAs($adminA)->get(ProductoResource::getUrl('index', tenant: $empresaA));

        Livewire::actingAs($adminA)
            ->test(ListProductos::class)
            ->searchTable('888888')
            ->assertCanNotSeeTableRecords([$productoA, $productoTobogan]);
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

    /**
     * 15. VentaService::registrar() rechaza un cliente de OTRA empresa aunque el id exista.
     * A diferencia de CompraService/DevolucionCompraService (que usan findOrFail y dejan que
     * ModelNotFoundException se propague), VentaService valida con find() + null-check propio
     * y lanza VentaInvalidaException — mismo resultado (rechaza el cruce), excepción distinta.
     */
    public function test_15_venta_no_permite_cliente_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'producto' => $productoA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['cliente' => $clienteTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $empresaA->refresh();
        $this->comoEmpresa($empresaA);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('El cliente indicado no existe o está inactivo.');

        app(VentaService::class)->registrar([
            'cliente_id' => $clienteTobogan->id,
            'lineas' => [['producto_id' => $productoA->id, 'cantidad' => 1]],
        ], $empresaA);
    }

    /** 16. VentaService::registrar() rechaza un producto de OTRA empresa aunque el id exista. */
    public function test_16_venta_no_permite_producto_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'cliente' => $clienteA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['producto' => $productoTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $empresaA->refresh();
        $this->comoEmpresa($empresaA);

        $this->expectException(VentaInvalidaException::class);

        app(VentaService::class)->registrar([
            'cliente_id' => $clienteA->id,
            'lineas' => [['producto_id' => $productoTobogan->id, 'cantidad' => 1]],
        ], $empresaA);
    }

    /** 17. CompraService::crear() rechaza un proveedor de OTRA empresa aunque el id exista. */
    public function test_17_compra_no_permite_proveedor_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'producto' => $productoA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['proveedor' => $proveedorTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaA);

        $this->expectException(ModelNotFoundException::class);

        app(CompraService::class)->crear([
            'proveedor_id' => $proveedorTobogan->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $productoA->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $adminA->id, $empresaA);
    }

    /** 18. CompraService::crear() rechaza un producto de OTRA empresa aunque el id exista. */
    public function test_18_compra_no_permite_producto_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA, 'proveedor' => $proveedorA] =
            $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['producto' => $productoTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaA);

        $this->expectException(ModelNotFoundException::class);

        app(CompraService::class)->crear([
            'proveedor_id' => $proveedorA->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $productoTobogan->id, 'cantidad' => 1, 'costo_unitario' => 10],
            ],
        ], $adminA->id, $empresaA);
    }

    /** 19. DevolucionCompraService::crear() rechaza una compra de OTRA empresa aunque el id exista. */
    public function test_19_devolucion_no_permite_compra_de_otra_empresa(): void
    {
        ['empresa' => $empresaA, 'admin' => $adminA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'admin' => $adminTobogan, 'producto' => $productoTobogan, 'proveedor' => $proveedorTobogan] =
            $this->crearEmpresaConDatos('Tobogán', '131000002');

        $this->comoEmpresa($empresaTobogan);
        $compraTobogan = app(CompraService::class)->crear([
            'proveedor_id' => $proveedorTobogan->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => false,
            'lineas' => [
                ['producto_id' => $productoTobogan->id, 'cantidad' => 5, 'costo_unitario' => 10],
            ],
        ], $adminTobogan->id, $empresaTobogan);
        $detalleTobogan = $compraTobogan->detalles()->first();

        $this->comoEmpresa($empresaA);

        $this->expectException(ModelNotFoundException::class);

        app(DevolucionCompraService::class)->crear([
            'compra_id' => $compraTobogan->id,
            'fecha' => now(),
            'motivo' => 'Intento cruzado',
            'lineas' => [
                ['detalle_compra_id' => $detalleTobogan->id, 'cantidad' => 1],
            ],
        ], $adminA->id, $empresaA);
    }

    /**
     * 20. SecuenciaNcfService::siguiente() nunca consume el contador de la secuencia activa de
     * OTRA empresa, aunque ambas tengan una secuencia activa del mismo tipo_comprobante (bug real
     * detectado y corregido en F3.5: antes de la corrección, esto podía "robarle" el NCF a otra
     * empresa).
     */
    public function test_20_secuencia_ncf_no_consume_secuencia_de_otra_empresa(): void
    {
        ['empresa' => $empresaA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $secuenciaA = SecuenciaNcf::create([
            'empresa_id' => $empresaA->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'prefijo' => 'E31',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 100,
            'vencimiento' => today()->addYear(),
            'activa' => true,
        ]);

        $secuenciaTobogan = SecuenciaNcf::create([
            'empresa_id' => $empresaTobogan->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL->value,
            'prefijo' => 'E31',
            'secuencia_desde' => 500,
            'secuencia_actual' => 500,
            'secuencia_hasta' => 600,
            'vencimiento' => today()->addYear(),
            'activa' => true,
        ]);

        $this->comoEmpresa($empresaA);

        $ncf = app(SecuenciaNcfService::class)->siguiente(TipoComprobante::FACTURA_CREDITO_FISCAL, $empresaA);

        $this->assertSame('E310000000001', $ncf);
        $this->assertEquals(2, $secuenciaA->fresh()->secuencia_actual);
        $this->assertEquals(500, $secuenciaTobogan->fresh()->secuencia_actual);
    }

    /** 21. El código de producto es único POR EMPRESA (F2): dos empresas pueden compartir el mismo código. */
    public function test_21_unicidad_codigo_producto_es_por_empresa(): void
    {
        ['empresa' => $empresaA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $productoA = Producto::create([
            'empresa_id' => $empresaA->id,
            'codigo' => 'PROD-001',
            'nombre' => 'Producto compartido A',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 10,
            'precio' => 20,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $productoTobogan = Producto::create([
            'empresa_id' => $empresaTobogan->id,
            'codigo' => 'PROD-001',
            'nombre' => 'Producto compartido Tobogán',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 10,
            'precio' => 20,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $this->assertDatabaseHas('productos', ['id' => $productoA->id, 'codigo' => 'PROD-001', 'empresa_id' => $empresaA->id]);
        $this->assertDatabaseHas('productos', ['id' => $productoTobogan->id, 'codigo' => 'PROD-001', 'empresa_id' => $empresaTobogan->id]);
    }

    /** 22. El NCF de una venta es único POR EMPRESA (F2): dos empresas pueden emitir el mismo NCF. */
    public function test_22_unicidad_ncf_es_por_empresa(): void
    {
        ['empresa' => $empresaA, 'cliente' => $clienteA] = $this->crearEmpresaConDatos('Empresa A', '131000001');
        ['empresa' => $empresaTobogan, 'cliente' => $clienteTobogan] = $this->crearEmpresaConDatos('Tobogán', '131000002');

        $ventaA = Venta::create([
            'empresa_id' => $empresaA->id,
            'cliente_id' => $clienteA->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'ncf' => 'B0100000001',
            'fecha' => now(),
            'subtotal' => '100.00',
            'total_itbis' => '18.00',
            'total' => '118.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ]);

        $ventaTobogan = Venta::create([
            'empresa_id' => $empresaTobogan->id,
            'cliente_id' => $clienteTobogan->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'ncf' => 'B0100000001',
            'fecha' => now(),
            'subtotal' => '200.00',
            'total_itbis' => '36.00',
            'total' => '236.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ]);

        $this->assertDatabaseHas('ventas', ['id' => $ventaA->id, 'ncf' => 'B0100000001', 'empresa_id' => $empresaA->id]);
        $this->assertDatabaseHas('ventas', ['id' => $ventaTobogan->id, 'ncf' => 'B0100000001', 'empresa_id' => $empresaTobogan->id]);
    }
}
