<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\DetalleVenta;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportesPdfTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function crearVentaDeEjemplo(User $vendedor): void
    {
        $cliente = Cliente::create([
            'tipo_documento' => TipoDocumentoCliente::CEDULA,
            'documento' => '00112345678',
            'nombre' => 'Cliente de prueba',
            'activo' => true,
        ]);

        $producto = Producto::create([
            'codigo' => 'P-001',
            'nombre' => 'Producto de prueba',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => '50.00',
            'precio' => '100.00',
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => '1.000',
            'stock_minimo' => '5.000',
            'activo' => true,
        ]);

        $venta = Venta::create([
            'cliente_id' => $cliente->id,
            'user_id' => $vendedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'fecha' => now(),
            'subtotal' => '100.00',
            'total_itbis' => '18.00',
            'total' => '118.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ]);

        DetalleVenta::create([
            'venta_id' => $venta->id,
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => '1.000',
            'precio_unitario' => '100.00',
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'itbis_monto' => '18.00',
            'subtotal' => '100.00',
        ]);
    }

    public function test_los_pdf_de_los_reportes_se_generan_para_quien_tiene_ver_reportes(): void
    {
        $usuario = $this->usuarioConPermiso();
        $this->crearVentaDeEjemplo($usuario);

        $desde = now()->startOfMonth()->toDateString();
        $hasta = now()->endOfMonth()->toDateString();

        foreach ([
            route('reportes.ventas.pdf', ['desde' => $desde, 'hasta' => $hasta]),
            route('reportes.top-productos.pdf', ['desde' => $desde, 'hasta' => $hasta]),
            route('reportes.ventas-por-cliente.pdf', ['desde' => $desde, 'hasta' => $hasta]),
            route('reportes.ventas-por-vendedor.pdf', ['desde' => $desde, 'hasta' => $hasta]),
            route('reportes.inventario.pdf'),
        ] as $url) {
            $response = $this->actingAs($usuario)->get($url);
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_usuario_sin_ver_reportes_no_puede_descargar_los_pdf(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        $this->actingAs($usuario)->get(route('reportes.ventas.pdf'))->assertForbidden();
        $this->actingAs($usuario)->get(route('reportes.inventario.pdf'))->assertForbidden();
    }
}
