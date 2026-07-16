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

class VentaTicketControllerTest extends TestCase
{
    use RefreshDatabase;

    private function crearVentaConDetalle(array $overrides = []): Venta
    {
        $cliente = Cliente::create([
            'tipo_documento' => TipoDocumentoCliente::CEDULA,
            'documento' => '00112345678',
            'nombre' => 'Cliente ticket',
            'activo' => true,
        ]);

        $producto = Producto::create([
            'codigo' => 'P-900',
            'nombre' => 'Producto ticket',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => '10.00',
            'precio' => '50.00',
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => '0.000',
            'stock_minimo' => '0.000',
            'activo' => true,
        ]);

        $venta = Venta::create(array_merge([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'ncf' => 'E320000000051',
            'fecha' => now(),
            'subtotal' => '50.00',
            'total_itbis' => '9.00',
            'total' => '59.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ], $overrides));

        DetalleVenta::create([
            'venta_id' => $venta->id,
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => '1.000',
            'precio_unitario' => '50.00',
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'itbis_monto' => '9.00',
            'subtotal' => '50.00',
        ]);

        return $venta;
    }

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    public function test_el_ticket_muestra_ncf_cliente_lineas_y_totales(): void
    {
        $usuario = $this->usuarioConPermiso();
        $venta = $this->crearVentaConDetalle();

        $this->actingAs($usuario)
            ->get(route('ventas.ticket', $venta))
            ->assertOk()
            ->assertSee('E320000000051')
            ->assertSee('Producto ticket')
            ->assertSee('59.00')
            ->assertSee('width: 80mm', false);
    }

    public function test_el_ticket_respeta_el_ancho_58mm_por_query_param(): void
    {
        $usuario = $this->usuarioConPermiso();
        $venta = $this->crearVentaConDetalle();

        $this->actingAs($usuario)
            ->get(route('ventas.ticket', $venta).'?ancho=58')
            ->assertOk()
            ->assertSee('width: 58mm', false);
    }

    public function test_el_ticket_de_una_venta_anulada_muestra_el_aviso(): void
    {
        $usuario = $this->usuarioConPermiso();
        $venta = $this->crearVentaConDetalle(['estado' => EstadoVenta::ANULADA]);

        $this->actingAs($usuario)
            ->get(route('ventas.ticket', $venta))
            ->assertOk()
            ->assertSee('COMPROBANTE ANULADO');
    }

    public function test_usuario_sin_permiso_no_puede_ver_el_ticket(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        $venta = $this->crearVentaConDetalle();

        $this->actingAs($usuario)
            ->get(route('ventas.ticket', $venta))
            ->assertForbidden();
    }
}
