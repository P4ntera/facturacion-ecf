<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Filament\Pages\Reportes\ReporteInventario;
use App\Filament\Pages\Reportes\ReporteTopProductos;
use App\Filament\Pages\Reportes\ReporteVentas;
use App\Filament\Pages\Reportes\ReporteVentasPorCliente;
use App\Filament\Pages\Reportes\ReporteVentasPorVendedor;
use App\Models\Cliente;
use App\Models\DetalleVenta;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportesPagesTest extends TestCase
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

    public function test_las_paginas_de_reportes_cargan_para_quien_tiene_ver_reportes(): void
    {
        $usuario = $this->usuarioConPermiso();
        $this->crearVentaDeEjemplo($usuario);

        $this->actingAs($usuario)->get(ReporteVentas::getUrl())->assertOk()->assertSee('Cliente de prueba');
        $this->actingAs($usuario)->get(ReporteTopProductos::getUrl())->assertOk()->assertSee('Producto de prueba');
        $this->actingAs($usuario)->get(ReporteVentasPorCliente::getUrl())->assertOk()->assertSee('Cliente de prueba');
        $this->actingAs($usuario)->get(ReporteVentasPorVendedor::getUrl())->assertOk();
        $this->actingAs($usuario)->get(ReporteInventario::getUrl())->assertOk()->assertSee('Producto de prueba');
    }

    public function test_usuario_sin_ver_reportes_no_puede_entrar_a_los_reportes(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        $this->actingAs($usuario)->get(ReporteVentas::getUrl())->assertForbidden();
        $this->actingAs($usuario)->get(ReporteInventario::getUrl())->assertForbidden();
    }
}
