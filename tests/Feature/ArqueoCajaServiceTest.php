<?php

namespace Tests\Feature;

use App\Enums\EstadoArqueoCaja;
use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\ArqueoCajaService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ArqueoCajaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function secuencia(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function producto(string $codigo = 'ARQ-P1'): Producto
    {
        return Producto::create([
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'stock' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    private function cliente(): Cliente
    {
        return Cliente::create(['nombre' => 'Consumidor Final', 'activo' => true]);
    }

    /** Registra una venta de un producto de RD$100 (18% ITBIS -> total 118.00) con la forma de pago dada. */
    private function vender(int $arqueoId, int $userId, FormaPago $formaPago, string $codigoProducto): void
    {
        app(VentaService::class)->registrar([
            'cliente_id' => $this->cliente()->id,
            'user_id' => $userId,
            'forma_pago' => $formaPago,
            'arqueo_caja_id' => $arqueoId,
            'lineas' => [['producto_id' => $this->producto($codigoProducto)->id, 'cantidad' => 1]],
        ]);
    }

    public function test_abrir_crea_arqueo_en_estado_abierto(): void
    {
        $user = User::factory()->create();

        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $user->id);

        $this->assertEquals(EstadoArqueoCaja::ABIERTO, $arqueo->estado);
        $this->assertEquals(500.00, (float) $arqueo->fondo_inicial);
        $this->assertEquals($user->id, $arqueo->user_id);
        $this->assertNotNull($arqueo->abierto_en);
    }

    public function test_no_permite_abrir_dos_arqueos_para_el_mismo_usuario(): void
    {
        $user = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $service->abrir('500.00', $user->id);

        $this->expectException(RuntimeException::class);
        $service->abrir('300.00', $user->id);
    }

    public function test_cerrar_calcula_efectivo_esperado_y_diferencia_correctamente(): void
    {
        $this->secuencia();
        $user = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $user->id);
        $this->vender($arqueo->id, $user->id, FormaPago::EFECTIVO, 'ARQ-EF-1');
        $this->vender($arqueo->id, $user->id, FormaPago::EFECTIVO, 'ARQ-EF-2');

        // fondo 500 + 2 ventas en efectivo de 118 c/u = 736; se cuentan 736 exactos -> diferencia 0.
        $cerrado = $service->cerrar($arqueo, '736.00', null, $user->id);

        $this->assertEquals(EstadoArqueoCaja::CERRADO, $cerrado->estado);
        $this->assertEquals(236.00, (float) $cerrado->total_ventas_efectivo);
        $this->assertEquals(736.00, (float) $cerrado->efectivo_esperado);
        $this->assertEquals(736.00, (float) $cerrado->efectivo_contado);
        $this->assertEquals(0.00, (float) $cerrado->diferencia);
        $this->assertNotNull($cerrado->cerrado_en);
    }

    public function test_cerrar_excluye_ventas_anuladas_del_calculo(): void
    {
        $this->secuencia();
        $user = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $user->id);
        $this->vender($arqueo->id, $user->id, FormaPago::EFECTIVO, 'ARQ-ANUL-1');

        $venta = $arqueo->ventas()->first();
        app(VentaService::class)->anular($venta, 'Prueba', $user->id);

        $cerrado = $service->cerrar($arqueo, '500.00', null, $user->id);

        $this->assertEquals(0.00, (float) $cerrado->total_ventas_efectivo);
        $this->assertEquals(500.00, (float) $cerrado->efectivo_esperado);
    }

    public function test_cerrar_excluye_formas_de_pago_no_efectivo_del_efectivo_esperado(): void
    {
        $this->secuencia();
        $user = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $user->id);
        $this->vender($arqueo->id, $user->id, FormaPago::EFECTIVO, 'ARQ-MIX-EF');
        $this->vender($arqueo->id, $user->id, FormaPago::TARJETA, 'ARQ-MIX-TAR');
        $this->vender($arqueo->id, $user->id, FormaPago::TRANSFERENCIA, 'ARQ-MIX-TRA');

        $cerrado = $service->cerrar($arqueo, '618.00', null, $user->id);

        $this->assertEquals(118.00, (float) $cerrado->total_ventas_efectivo);
        $this->assertEquals(118.00, (float) $cerrado->total_ventas_tarjeta);
        $this->assertEquals(118.00, (float) $cerrado->total_ventas_transferencia);
        // Esperado = fondo (500) + SOLO efectivo (118), no incluye tarjeta ni transferencia.
        $this->assertEquals(618.00, (float) $cerrado->efectivo_esperado);
    }

    public function test_no_permite_cerrar_un_arqueo_ya_cerrado(): void
    {
        $user = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $user->id);
        $service->cerrar($arqueo, '500.00', null, $user->id);

        $this->expectException(RuntimeException::class);
        $service->cerrar($arqueo, '500.00', null, $user->id);
    }

    public function test_solo_quien_abrio_puede_cerrar(): void
    {
        $cajero = User::factory()->create();
        $otro = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $cajero->id);

        $this->expectException(RuntimeException::class);
        $service->cerrar($arqueo, '500.00', null, $otro->id);
    }

    public function test_arqueo_abierto_de_retorna_null_si_no_hay_ninguno_abierto(): void
    {
        $user = User::factory()->create();

        $this->assertNull(app(ArqueoCajaService::class)->arqueoAbiertoDe($user->id));
    }
}
