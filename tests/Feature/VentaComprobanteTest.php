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
use App\Settings\EmpresaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VentaComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'ventas.imprimir', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['ventas.imprimir']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function crearVenta(): Venta
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

        $cliente = Cliente::create(['nombre' => 'Cliente PDF', 'activo' => true]);

        $producto = Producto::create([
            'codigo' => 'PDF-001',
            'nombre' => 'Producto PDF',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'user_id' => null,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);
    }

    public function test_genera_el_pdf_del_comprobante(): void
    {
        $venta = $this->crearVenta();

        $response = $this->actingAs($this->usuarioAutorizado())
            ->get(route('ventas.pdf', $venta));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_un_usuario_sin_permiso_no_puede_ver_el_pdf(): void
    {
        $venta = $this->crearVenta();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('ventas.pdf', $venta));

        $response->assertForbidden();
    }

    /**
     * Se prueba el Blade directamente (no el PDF binario ya comprimido por dompdf): la venta
     * viene ACEPTADA del FakeGateway (cola 'sync' en pruebas), así que ya tiene dgii_url y
     * codigo_seguridad para el timbre.
     */
    public function test_el_comprobante_incluye_el_qr_y_el_codigo_de_seguridad_cuando_esta_aceptada(): void
    {
        $venta = $this->crearVenta()->refresh()->load('detalles.producto', 'cliente');
        $this->assertNotNull($venta->dgii_url);

        $html = view('ventas.comprobante', [
            'venta' => $venta,
            'empresa' => app(EmpresaSettings::class),
            'qrTimbre' => base64_encode((string) QrCode::format('png')->size(120)->generate($venta->dgii_url)),
        ])->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString($venta->codigo_seguridad, $html);
        $this->assertStringContainsString($venta->dgii_url, $html);
    }

    public function test_el_comprobante_no_muestra_el_timbre_si_no_hay_dgii_url(): void
    {
        $venta = $this->crearVenta()->refresh()->load('detalles.producto', 'cliente');

        $html = view('ventas.comprobante', [
            'venta' => $venta,
            'empresa' => app(EmpresaSettings::class),
            'qrTimbre' => null,
        ])->render();

        $this->assertStringNotContainsString('data:image/png;base64,', $html);
    }
}
