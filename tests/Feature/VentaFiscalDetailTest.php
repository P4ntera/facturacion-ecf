<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Resources\VentaResource\Pages\ViewVenta;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VentaFiscalDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'registrar_ventas', 'guard_name' => 'web']);
    }

    private function usuarioAutorizado(): User
    {
        $rol = Role::firstOrCreate(['name' => 'Vendedor-fiscal', 'guard_name' => 'web']);
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
            'codigo' => 'FISCAL-1',
            'nombre' => 'Producto fiscal',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $cliente = Cliente::create(['nombre' => 'Cliente fiscal', 'activo' => true]);

        return app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->refresh();
    }

    public function test_muestra_trackid_codigo_de_seguridad_y_ambiente(): void
    {
        $venta = $this->crearVentaAceptada();

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ViewVenta::class, ['record' => $venta->id])
            ->assertSuccessful()
            ->assertSee($venta->ecf_track_id)
            ->assertSee($venta->codigo_seguridad)
            ->assertSee('Pruebas'); // etiqueta de AmbienteEcf::TESTECF
    }

    public function test_muestra_el_timeline_de_eventos_en_espanol(): void
    {
        $venta = $this->crearVentaAceptada();

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ViewVenta::class, ['record' => $venta->id])
            ->assertSuccessful()
            ->assertSee('Autenticación exitosa ante el PAC')
            ->assertSee('Documento firmado digitalmente')
            ->assertSee('Respuesta de la DGII');
    }

    public function test_muestra_el_qr_del_timbre_y_el_enlace_de_descarga_del_xml(): void
    {
        $venta = $this->crearVentaAceptada();

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ViewVenta::class, ['record' => $venta->id])
            ->assertSuccessful()
            ->assertSee($venta->dgii_url)
            ->assertSeeHtml('<svg')
            ->assertSee('Descargar XML');
    }

    public function test_no_muestra_seccion_fiscal_si_la_venta_no_es_electronica(): void
    {
        $venta = $this->crearVentaAceptada();
        $venta->update(['ncf' => null]);

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ViewVenta::class, ['record' => $venta->id])
            ->assertSuccessful()
            ->assertDontSee('Historial DGII');
    }
}
