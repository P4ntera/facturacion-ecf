<?php

namespace Tests\Feature;

use App\Enums\CategoriaSabor;
use App\Enums\ModoPrecioHelado;
use App\Enums\PerfilEmpresa;
use App\Exceptions\VentaInvalidaException;
use App\Models\ComboHelado;
use App\Models\Empresa;
use App\Models\PresentacionHelado;
use App\Models\SaborHelado;
use App\Models\ToppingHelado;
use App\Services\HeladeriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeladeriaServiceTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private HeladeriaService $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::factory()->create(['perfil' => PerfilEmpresa::HELADERIA]);
        $this->servicio = app(HeladeriaService::class);
    }

    private function presentacion(array $datos = []): PresentacionHelado
    {
        return PresentacionHelado::create($datos + ['empresa_id' => $this->empresa->id, 'nombre' => 'Copa', 'icono' => '🍨', 'scoops_incluidos' => 2, 'toppings_incluidos' => 1, 'precio' => 200, 'activo' => true, 'orden' => 1]);
    }

    private function sabor(string $nombre, bool $premium = false, ?Empresa $empresa = null): SaborHelado
    {
        return SaborHelado::create(['empresa_id' => ($empresa ?? $this->empresa)->id, 'nombre' => $nombre, 'color_hex' => '#ffffff', 'categoria' => CategoriaSabor::cases()[0], 'premium' => $premium, 'disponible' => true, 'activo' => true, 'orden' => 1]);
    }

    private function topping(string $nombre, float $precio, bool $activo = true): ToppingHelado
    {
        return ToppingHelado::create(['empresa_id' => $this->empresa->id, 'nombre' => $nombre, 'icono' => '🍫', 'precio' => $precio, 'activo' => $activo, 'orden' => 1]);
    }

    public function test_por_presentacion_cobra_la_presentacion_y_solo_los_toppings_que_exceden_los_incluidos(): void
    {
        $this->empresa->config()->update(['modo_precio_helado' => ModoPrecioHelado::POR_PRESENTACION]);
        $copa = $this->presentacion();
        $barato = $this->topping('Chispas', 20);
        $caro = $this->topping('Nueces', 50);

        $sinExtras = $this->servicio->calcularPrecioArmado($copa, ['sabores' => [$this->sabor('Vainilla')->id], 'toppings' => [$barato->id]], $this->empresa->fresh());
        $conExtra = $this->servicio->calcularPrecioArmado($copa, ['sabores' => [$this->sabor('Fresa')->id], 'toppings' => [$barato->id, $caro->id]], $this->empresa->fresh());

        // Un topping incluido: no se cobra.
        $this->assertEqualsWithDelta(200.0, $sinExtras['total'], 0.001);
        // Dos toppings, uno incluido: se cobra UNO, el más caro (regla del servicio).
        $this->assertEqualsWithDelta(250.0, $conExtra['total'], 0.001);
        $this->assertStringContainsString('Fresa', $conExtra['descripcion']);
        $this->assertStringContainsString('Nueces', $conExtra['descripcion']);
    }

    public function test_por_scoop_suma_cada_bola_con_su_precio_normal_o_premium(): void
    {
        $this->empresa->config()->update([
            'modo_precio_helado' => ModoPrecioHelado::POR_SCOOP,
            'precio_scoop_normal' => 75,
            'precio_scoop_premium' => 100,
        ]);
        $cono = $this->presentacion(['nombre' => 'Cono', 'precio' => 25, 'toppings_incluidos' => 0]);

        $precio = $this->servicio->calcularPrecioArmado(
            $cono,
            ['sabores' => [$this->sabor('Vainilla')->id, $this->sabor('Pistacho', premium: true)->id], 'toppings' => []],
            $this->empresa->fresh(),
        );

        // 25 (cono) + 75 (normal) + 100 (premium)
        $this->assertEqualsWithDelta(200.0, $precio['total'], 0.001);
    }

    public function test_los_sabores_de_otra_empresa_no_cuentan_en_el_precio_ni_en_la_descripcion(): void
    {
        $this->empresa->config()->update(['modo_precio_helado' => ModoPrecioHelado::POR_SCOOP, 'precio_scoop_normal' => 75, 'precio_scoop_premium' => 100]);
        $otra = Empresa::factory()->create();
        $ajeno = $this->sabor('Sabor Ajeno', premium: true, empresa: $otra);
        $cono = $this->presentacion(['nombre' => 'Cono', 'precio' => 25, 'toppings_incluidos' => 0]);

        $precio = $this->servicio->calcularPrecioArmado($cono, ['sabores' => [$this->sabor('Vainilla')->id, $ajeno->id], 'toppings' => []], $this->empresa->fresh());

        $this->assertEqualsWithDelta(100.0, $precio['total'], 0.001);
        $this->assertStringNotContainsString('Ajeno', $precio['descripcion']);
    }

    public function test_los_totales_respetan_si_el_precio_incluye_itbis_o_va_aparte(): void
    {
        $items = [['tipo' => 'combo', 'descripcion' => 'Combo', 'precio' => 118, 'cantidad' => 2]];

        $this->empresa->config()->update(['aplica_itbis' => true, 'precio_incluye_itbis' => false]);
        $aparte = $this->servicio->calcularTotales($items, $this->empresa->fresh());

        $this->empresa->config()->update(['aplica_itbis' => true, 'precio_incluye_itbis' => true]);
        $incluido = $this->servicio->calcularTotales($items, $this->empresa->fresh());

        $this->empresa->config()->update(['aplica_itbis' => false]);
        $sinItbis = $this->servicio->calcularTotales($items, $this->empresa->fresh());

        $this->assertSame(['subtotal' => '236.00', 'itbis' => '42.48', 'total' => '278.48'], $aparte);
        $this->assertSame(['subtotal' => '200.00', 'itbis' => '36.00', 'total' => '236.00'], $incluido);
        $this->assertSame('236.00', $sinItbis['total']);
        $this->assertSame('0.00', $sinItbis['itbis']);
    }

    public function test_revalidar_carrito_repone_precio_y_descripcion_desde_el_catalogo(): void
    {
        $this->empresa->config()->update(['modo_precio_helado' => ModoPrecioHelado::POR_PRESENTACION]);
        $copa = $this->presentacion();
        $vainilla = $this->sabor('Vainilla');
        $combo = ComboHelado::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Combo Pareja', 'icono' => '🍨', 'scoops_incluidos' => 2, 'toppings_incluidos' => 0, 'precio' => 350, 'activo' => true, 'orden' => 1]);

        $carrito = $this->servicio->revalidarCarrito([
            ['tipo' => 'armado', 'descripcion' => 'Gratis', 'precio' => 1, 'cantidad' => 2, 'detalle' => ['presentacion_id' => $copa->id, 'sabores' => [$vainilla->id], 'toppings' => []]],
            ['tipo' => 'combo', 'descripcion' => 'Gratis', 'precio' => 0.5, 'cantidad' => -4, 'detalle' => ['combo_id' => $combo->id]],
        ], $this->empresa->fresh());

        $this->assertEqualsWithDelta(200.0, $carrito[0]['precio'], 0.001);
        $this->assertSame('Copa: Vainilla', $carrito[0]['descripcion']);
        $this->assertSame(2, $carrito[0]['cantidad']);
        $this->assertEqualsWithDelta(350.0, $carrito[1]['precio'], 0.001);
        $this->assertSame('Combo Pareja', $carrito[1]['descripcion']);
        $this->assertSame(1, $carrito[1]['cantidad'], 'Una cantidad negativa o cero se trata como 1.');
    }

    public function test_revalidar_carrito_rechaza_items_desactivados_ajenos_o_desconocidos(): void
    {
        $otra = Empresa::factory()->create();
        $comboAjeno = ComboHelado::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno', 'icono' => '🍨', 'scoops_incluidos' => 1, 'toppings_incluidos' => 0, 'precio' => 10, 'activo' => true, 'orden' => 1]);
        $comboInactivo = ComboHelado::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Viejo', 'icono' => '🍨', 'scoops_incluidos' => 1, 'toppings_incluidos' => 0, 'precio' => 10, 'activo' => false, 'orden' => 1]);

        $casos = [
            ['tipo' => 'combo', 'descripcion' => 'Combo ajeno', 'precio' => 10, 'cantidad' => 1, 'detalle' => ['combo_id' => $comboAjeno->id]],
            ['tipo' => 'combo', 'descripcion' => 'Combo viejo', 'precio' => 10, 'cantidad' => 1, 'detalle' => ['combo_id' => $comboInactivo->id]],
            ['tipo' => 'armado', 'descripcion' => 'Sin detalle', 'precio' => 10, 'cantidad' => 1, 'detalle' => []],
            ['tipo' => 'inventado', 'descripcion' => 'Raro', 'precio' => 10, 'cantidad' => 1],
        ];

        foreach ($casos as $item) {
            try {
                $this->servicio->revalidarCarrito([$item], $this->empresa);
                $this->fail("Debió rechazar «{$item['descripcion']}».");
            } catch (VentaInvalidaException $e) {
                // Mensaje pensado para el cajero, sin detalles técnicos.
                $this->assertStringNotContainsStringIgnoringCase('sql', $e->getMessage());
            }
        }
    }
}
