<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Resources\DocumentoRecibidoResource;
use App\Filament\Resources\SecuenciaNcfResource;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\User;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsaEcfTest extends TestCase
{
    use RefreshDatabase;

    private function producto(float $stock = 10): Producto
    {
        return Producto::create([
            'codigo' => 'SE-001',
            'nombre' => 'Producto sin e-CF',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => $stock,
            'stock_minimo' => 1,
            'activo' => true,
        ]);
    }

    public function test_una_empresa_sin_ecf_registra_ventas_sin_ncf_y_con_estado_fiscal_no_aplica(): void
    {
        Queue::fake();

        $this->empresaDefault->update(['usa_ecf' => false]);

        $producto = $this->producto();
        $cliente = Cliente::create(['nombre' => 'Cliente sin e-CF', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'user_id' => null,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertNull($venta->ncf);
        $this->assertSame(EstadoFiscal::NO_APLICA, $venta->estado_fiscal);
        $this->assertFalse($venta->esElectronica());

        Queue::assertNotPushed(EnviarEcfJob::class);
    }

    public function test_una_empresa_sin_ecf_no_exige_rnc_del_comprador_en_consumo_alto(): void
    {
        Queue::fake();

        $this->empresaDefault->update(['usa_ecf' => false]);

        $producto = $this->producto(stock: 5000);
        $cliente = Cliente::create(['nombre' => 'Consumidor sin RNC', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'user_id' => null,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 3000]],
        ], $this->empresaDefault);

        $this->assertSame('354000.00', $venta->total);
        $this->assertNull($venta->ncf);
    }

    public function test_secuencias_ncf_y_ecf_recibidos_se_ocultan_cuando_la_empresa_no_usa_ecf(): void
    {
        Permission::firstOrCreate(['name' => 'secuencias.administrar', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ecf.gestionar', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $rol->syncPermissions(['secuencias.administrar', 'ecf.gestionar']);

        $usuario = User::factory()->create(['empresa_id' => $this->empresaDefault->id]);
        $usuario->assignRole('Administrador');
        $this->actingAs($usuario);

        // Con e-CF activo (default de la fábrica), la permisología ya alcanza para entrar.
        $this->assertTrue(SecuenciaNcfResource::canAccess());
        $this->assertTrue(DocumentoRecibidoResource::canAccess());

        $this->empresaDefault->update(['usa_ecf' => false]);

        $this->assertFalse(SecuenciaNcfResource::canAccess());
        $this->assertFalse(DocumentoRecibidoResource::canAccess());
    }
}
