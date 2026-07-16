<?php

namespace Tests\Feature;

use App\Enums\AnchoPapel;
use App\Enums\ModuloImpresion;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoConexionImpresora;
use App\Enums\TipoProducto;
use App\Filament\Pages\PuntoDeVenta;
use App\Models\Impresora;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PuntoDeVentaImpresionTest extends TestCase
{
    use RefreshDatabase;

    private function vendedor(): User
    {
        Permission::firstOrCreate(['name' => 'registrar_ventas', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['registrar_ventas']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function habilitarSecuenciaNcfDeConsumo(): void
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
    }

    private function producto(): Producto
    {
        return Producto::create([
            'codigo' => 'POS-IMP',
            'nombre' => 'Producto POS impresión',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 5,
            'stock_minimo' => 1,
            'activo' => true,
        ]);
    }

    public function test_cobrar_sin_impresora_configurada_dispara_abrir_ticket_y_notifica(): void
    {
        $this->habilitarSecuenciaNcfDeConsumo();
        $producto = $this->producto();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->call('cobrar')
            ->assertDispatched('abrir-ticket')
            ->assertNotified();
    }

    public function test_cobrar_con_impresora_navegador_predeterminada_dispara_abrir_ticket_con_su_ancho(): void
    {
        Impresora::create([
            'nombre' => 'Mostrador',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'ancho_papel' => AnchoPapel::MM58,
            'predeterminada' => true,
        ]);

        $this->habilitarSecuenciaNcfDeConsumo();
        $producto = $this->producto();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->call('cobrar')
            ->assertDispatched('abrir-ticket', function (string $name, array $params) {
                return str_contains($params['url'], 'ancho=58');
            });
    }

    public function test_cobrar_con_impresora_red_inalcanzable_no_revierte_la_venta_y_notifica_el_error(): void
    {
        Impresora::create([
            'nombre' => 'Cocina',
            'tipo_conexion' => TipoConexionImpresora::RED,
            'ip' => '127.0.0.1',
            'puerto' => 1,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        $this->habilitarSecuenciaNcfDeConsumo();
        $producto = $this->producto();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->call('agregarProducto', $producto->id)
            ->call('cobrar')
            ->assertNotDispatched('abrir-ticket')
            ->assertNotified();

        // La venta se registró igual: un fallo de impresión nunca la afecta.
        $this->assertDatabaseCount('ventas', 1);
        $this->assertSame('4.000', $producto->fresh()->stock);
    }
}
