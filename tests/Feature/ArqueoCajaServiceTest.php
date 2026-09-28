<?php

namespace Tests\Feature;

use App\Enums\EstadoArqueoCaja;
use App\Enums\EstadoFiscal;
use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Models\ArqueoCaja;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Empresa;
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
        ], $this->empresaDefault);
    }

    public function test_abrir_crea_arqueo_en_estado_abierto(): void
    {
        $user = User::factory()->create();

        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $user->id, $this->empresaDefault);

        $this->assertEquals(EstadoArqueoCaja::ABIERTO, $arqueo->estado);
        $this->assertEquals(500.00, (float) $arqueo->fondo_inicial);
        $this->assertEquals($user->id, $arqueo->user_id);
        $this->assertNotNull($arqueo->abierto_en);
    }

    public function test_no_permite_abrir_dos_arqueos_para_el_mismo_usuario(): void
    {
        $user = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $service->abrir('500.00', $user->id, $this->empresaDefault);

        $this->expectException(RuntimeException::class);
        $service->abrir('300.00', $user->id, $this->empresaDefault);
    }

    public function test_cerrar_calcula_efectivo_esperado_y_diferencia_correctamente(): void
    {
        $this->secuencia();
        $user = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $user->id, $this->empresaDefault);
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

        $arqueo = $service->abrir('500.00', $user->id, $this->empresaDefault);
        $this->vender($arqueo->id, $user->id, FormaPago::EFECTIVO, 'ARQ-ANUL-1');

        $venta = $arqueo->ventas()->first();
        // Anulación simple: el e-CF no llegó a ser válido ante la DGII (sin Nota de Crédito).
        $venta->update(['estado_fiscal' => EstadoFiscal::RECHAZADO]);
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

        $arqueo = $service->abrir('500.00', $user->id, $this->empresaDefault);
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

        $arqueo = $service->abrir('500.00', $user->id, $this->empresaDefault);
        $service->cerrar($arqueo, '500.00', null, $user->id);

        $this->expectException(RuntimeException::class);
        $service->cerrar($arqueo, '500.00', null, $user->id);
    }

    public function test_solo_quien_abrio_puede_cerrar(): void
    {
        $cajero = User::factory()->create();
        $otro = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $cajero->id, $this->empresaDefault);

        $this->expectException(RuntimeException::class);
        $service->cerrar($arqueo, '500.00', null, $otro->id);
    }

    public function test_con_puede_cerrar_ajena_otro_usuario_si_puede_cerrarla(): void
    {
        $cajero = User::factory()->create();
        $administrador = User::factory()->create();
        $service = app(ArqueoCajaService::class);

        $arqueo = $service->abrir('500.00', $cajero->id, $this->empresaDefault);
        $cerrado = $service->cerrar($arqueo, '500.00', null, $administrador->id, puedeCerrarAjena: true);

        $this->assertEquals(EstadoArqueoCaja::CERRADO, $cerrado->estado);
        $this->assertEquals($cajero->id, $cerrado->user_id);
    }

    public function test_arqueo_abierto_de_retorna_null_si_no_hay_ninguno_abierto(): void
    {
        $user = User::factory()->create();

        $this->assertNull(app(ArqueoCajaService::class)->arqueoAbiertoDe($user->id, $this->empresaDefault));
    }

    public function test_una_caja_fisica_solo_admite_un_turno_abierto_a_la_vez(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $cajeroA = User::factory()->create();
        $cajeroB = User::factory()->create();

        $arqueo = app(ArqueoCajaService::class)->abrir('100', $cajeroA->id, $this->empresaDefault, $caja);
        $this->assertSame($caja->id, $arqueo->caja_id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La caja Caja 1 ya tiene un arqueo abierto por otro cajero.');

        app(ArqueoCajaService::class)->abrir('100', $cajeroB->id, $this->empresaDefault, $caja);
    }

    public function test_cerrado_el_turno_la_caja_se_puede_volver_a_abrir(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $cajeroA = User::factory()->create();
        $cajeroB = User::factory()->create();

        $arqueo = app(ArqueoCajaService::class)->abrir('100', $cajeroA->id, $this->empresaDefault, $caja);
        app(ArqueoCajaService::class)->cerrar($arqueo, '100', null, $cajeroA->id);

        $nuevo = app(ArqueoCajaService::class)->abrir('50', $cajeroB->id, $this->empresaDefault, $caja);

        $this->assertSame($nuevo->id, app(ArqueoCajaService::class)->arqueoAbiertoEnCaja($caja)?->id);
    }

    public function test_no_abre_turno_en_una_caja_de_otra_empresa_o_inactiva(): void
    {
        $otra = Empresa::factory()->create();
        $ajena = Caja::create(['empresa_id' => $otra->id, 'nombre' => 'Caja Ajena']);
        $inactiva = Caja::create(['nombre' => 'Caja Apagada', 'activo' => false]);
        $cajero = User::factory()->create();

        foreach ([$ajena, $inactiva] as $caja) {
            try {
                app(ArqueoCajaService::class)->abrir('0', $cajero->id, $this->empresaDefault, $caja);
                $this->fail('Debió rechazar la caja '.$caja->nombre);
            } catch (RuntimeException $e) {
                $this->assertSame('La caja indicada no existe o está inactiva.', $e->getMessage());
            }
        }

        $this->assertSame(0, ArqueoCaja::count());
    }
}
