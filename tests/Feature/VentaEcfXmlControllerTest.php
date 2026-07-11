<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VentaEcfXmlControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'registrar_ventas', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor-xml', 'guard_name' => 'web']);
        $rol->syncPermissions(['registrar_ventas']);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    /** El FakeGateway (cola 'sync' en pruebas) acepta la venta automáticamente al cobrar. */
    private function crearVentaAceptada(): Venta
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 100,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $producto = Producto::create([
            'codigo' => 'XML-1',
            'nombre' => 'Producto XML',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $cliente = Cliente::create(['nombre' => 'Cliente XML', 'activo' => true]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->refresh();
    }

    public function test_redirige_a_xml_url_si_ya_esta_disponible(): void
    {
        $venta = $this->crearVentaAceptada();

        $response = $this->actingAs($this->usuarioAutorizado())->get(route('ventas.ecf.xml', $venta));

        $response->assertRedirect($venta->xml_url);
    }

    public function test_descarga_el_xml_desde_el_gateway_si_no_hay_xml_url(): void
    {
        $venta = $this->crearVentaAceptada();
        $venta->update(['xml_url' => null]);

        $response = $this->actingAs($this->usuarioAutorizado())->get(route('ventas.ecf.xml', $venta));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/xml');
        $this->assertStringContainsString('<ECF>', $response->getContent());
    }

    public function test_404_si_la_venta_no_se_ha_enviado_al_pac(): void
    {
        $venta = $this->crearVentaAceptada();
        $venta->update(['xml_url' => null, 'pac_id' => null]);

        $response = $this->actingAs($this->usuarioAutorizado())->get(route('ventas.ecf.xml', $venta));

        $response->assertNotFound();
    }

    public function test_un_usuario_sin_permiso_no_puede_descargar_el_xml(): void
    {
        $venta = $this->crearVentaAceptada();

        $response = $this->actingAs(User::factory()->create())->get(route('ventas.ecf.xml', $venta));

        $response->assertForbidden();
    }
}
