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

class VentaComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'registrar_ventas', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['registrar_ventas']);

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
}
