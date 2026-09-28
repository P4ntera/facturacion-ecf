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

    /** Cada línea gravada muestra su ITBIS (total de la línea); una exenta no; el total se mantiene. */
    public function test_el_ticket_muestra_el_itbis_por_linea_y_omite_los_exentos(): void
    {
        $usuario = $this->usuarioConPermiso();
        $venta = $this->crearVentaConDetalle(['subtotal' => '80.00', 'total' => '89.00']);

        DetalleVenta::create([
            'venta_id' => $venta->id,
            'producto_id' => $venta->detalles()->value('producto_id'),
            'descripcion' => 'Producto exento',
            'cantidad' => '1.000',
            'precio_unitario' => '30.00',
            'tasa_itbis' => TasaItbis::CERO,
            'itbis_monto' => '0.00',
            'subtotal' => '30.00',
        ]);

        $this->actingAs($usuario)
            ->get(route('ventas.ticket', $venta))
            ->assertOk()
            ->assertSee('ITBIS 18%')
            ->assertSeeInOrder(['Producto ticket', 'ITBIS 18%', '9.00', 'Producto exento'])
            ->assertDontSee('ITBIS 0%')
            ->assertSee('min-height: 280px', false);
    }

    /** Con descuento global, el ticket lo muestra para que Subtotal - Descuento + ITBIS = TOTAL. */
    /**
     * Con descuento global (prorrateado en las líneas antes del ITBIS, como lo guarda
     * VentaService): la línea muestra su descuento, el subtotal es NETO (= suma de líneas) y
     * subtotal + ITBIS = TOTAL.
     */
    public function test_el_ticket_con_descuento_suma_lineas_subtotal_itbis_y_total(): void
    {
        $usuario = $this->usuarioConPermiso();
        // Bruto 50, 10% de descuento = 5, base neta 45, ITBIS 18% = 8.10, total 53.10.
        $conDescuento = $this->crearVentaConDetalle(['descuento' => '5.00', 'total_itbis' => '8.10', 'total' => '53.10']);
        $conDescuento->detalles()->update(['descuento' => '5.00', 'subtotal' => '45.00', 'itbis_monto' => '8.10']);

        $this->actingAs($usuario)
            ->get(route('ventas.ticket', $conDescuento))
            ->assertOk()
            ->assertSeeInOrder(['Producto ticket', '45.00', 'Desc.', '-5.00', 'ITBIS 18%', '8.10', 'Subtotal', '45.00', 'ITBIS', '8.10', 'TOTAL', '53.10', 'Incluye descuento de', '5.00']);

        $sinDescuento = $conDescuento;
        $sinDescuento->update(['descuento' => '0.00', 'total_itbis' => '9.00', 'total' => '59.00']);
        $sinDescuento->detalles()->update(['descuento' => '0.00', 'subtotal' => '50.00', 'itbis_monto' => '9.00']);

        $this->actingAs($usuario)
            ->get(route('ventas.ticket', $sinDescuento))
            ->assertOk()
            ->assertDontSee('Desc.')
            ->assertDontSee('Incluye descuento');
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
