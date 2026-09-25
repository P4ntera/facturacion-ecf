<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Exceptions\VentaInvalidaException;
use App\Filament\Resources\DocumentoRecibidoResource;
use App\Filament\Resources\SecuenciaNcfResource;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
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

    private function secuenciaFisicaConsumo(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'prefijo' => 'B02',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    /**
     * Desde el soporte de comprobantes físicos (tipo B): una empresa sin e-CF ya NO se queda sin
     * NCF — usa el equivalente físico (B02) por defecto, con NCF real y estado_fiscal NO_APLICA,
     * pero sin transmitir nada al PAC.
     */
    public function test_una_empresa_sin_ecf_usa_comprobante_fisico_por_defecto(): void
    {
        Queue::fake();

        $this->empresaDefault->update(['usa_ecf' => false]);
        $this->secuenciaFisicaConsumo();

        $producto = $this->producto();
        $cliente = Cliente::create(['nombre' => 'Cliente sin e-CF', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'user_id' => null,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);

        $this->assertSame('B0200000001', $venta->ncf);
        $this->assertSame(TipoComprobante::FACTURA_CONSUMO_FISICA, $venta->tipo_comprobante);
        $this->assertSame(EstadoFiscal::NO_APLICA, $venta->estado_fiscal);
        $this->assertFalse($venta->esElectronica());

        Queue::assertNotPushed(EnviarEcfJob::class);
    }

    /**
     * Sin secuencia física configurada, una empresa sin e-CF no puede vender en absoluto: ya no
     * existe el modo "sin ningún NCF" (toda venta necesita un rango real, físico o electrónico).
     */
    public function test_una_empresa_sin_ecf_y_sin_secuencia_fisica_configurada_no_puede_vender(): void
    {
        $this->empresaDefault->update(['usa_ecf' => false]);

        $producto = $this->producto();
        $cliente = Cliente::create(['nombre' => 'Cliente sin secuencia', 'activo' => true]);

        $this->expectExceptionMessage('No hay una secuencia de NCF activa para el comprobante B02');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    /**
     * La regla de RNC obligatorio en Consumo por encima del umbral es de la norma DGII sobre el
     * TIPO de comprobante (Consumo), no una particularidad del e-CF: aplica igual sin e-CF, con
     * el B02 físico.
     */
    public function test_una_empresa_sin_ecf_tambien_exige_rnc_del_comprador_en_consumo_alto(): void
    {
        Queue::fake();

        $this->empresaDefault->update(['usa_ecf' => false]);
        $this->secuenciaFisicaConsumo();

        $producto = $this->producto(stock: 5000);
        $cliente = Cliente::create(['nombre' => 'Consumidor sin RNC', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('Para facturas de consumo de RD$250,000 o más, el cliente con RNC/Cédula es obligatorio.');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'user_id' => null,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 3000]],
        ], $this->empresaDefault);
    }

    /**
     * Una empresa sin e-CF no puede emitir un tipo electrónico explícito (E32): ni siquiera con
     * una secuencia electrónica cargada, porque no está habilitada para transmitir al PAC.
     */
    public function test_una_empresa_sin_ecf_no_puede_forzar_un_tipo_electronico_explicito(): void
    {
        $this->empresaDefault->update(['usa_ecf' => false]);

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $producto = $this->producto();
        $cliente = Cliente::create(['nombre' => 'Cliente sin e-CF', 'activo' => true]);

        $this->expectException(VentaInvalidaException::class);
        $this->expectExceptionMessage('no tiene facturación electrónica habilitada');

        app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
    }

    /**
     * ECF_RECIBIDOS sigue exigiendo e-CF (es recepción electrónica de proveedores). ECF_SECUENCIAS
     * (Secuencias NCF) YA NO lo exige: sin e-CF, la empresa todavía necesita gestionar sus propios
     * rangos físicos (B0X) — ocultarlo la dejaría sin forma de cargar uno.
     */
    public function test_secuencias_ncf_sigue_accesible_sin_ecf_pero_ecf_recibidos_no(): void
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

        $this->assertTrue(SecuenciaNcfResource::canAccess());
        $this->assertFalse(DocumentoRecibidoResource::canAccess());
    }
}
