<?php

namespace Tests\Feature;

use App\Filament\Pages\PuntoDeVentaTouch;
use App\Livewire\DisplayCliente;
use App\Models\Caja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class DisplayClienteTest extends TestCase
{
    use RefreshDatabase;

    private function publicar(Caja $caja, array $datos): void
    {
        Cache::put($caja->claveCacheDisplay(), array_merge([
            'estado' => 'venta',
            'items' => [[
                'clave' => '0-1-x', 'nombre' => 'Arroz La Garza 5lb', 'cantidad' => '2',
                'precio' => '225.00', 'descuento' => '0.00', 'importe' => '450.00',
            ]],
            'subtotal' => '450.00',
            'descuento' => '0.00',
            'itbis' => '81.00',
            'total' => '531.00',
            'cajero' => 'Juan',
            'updated_at' => now()->timestamp,
        ], $datos), now()->addHour());
    }

    public function test_crear_una_caja_genera_un_token_de_64_caracteres(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);

        $this->assertSame(64, strlen($caja->display_token));
        $this->assertNotSame($caja->display_token, Caja::create(['nombre' => 'Caja 2'])->display_token);
    }

    public function test_es_publico_y_muestra_el_carrito_de_su_caja(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $this->publicar($caja, []);

        $this->get(route('display.cliente', $caja->display_token))
            ->assertOk()
            ->assertSee('Arroz La Garza 5lb')
            ->assertSee('531.00')
            ->assertSee('Caja 1');
    }

    public function test_no_muestra_datos_internos_de_la_venta(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $this->publicar($caja, []);

        $this->get(route('display.cliente', $caja->display_token))
            ->assertDontSee($caja->claveCacheDisplay());
    }

    public function test_token_inexistente_o_caja_inactiva_da_404(): void
    {
        $inactiva = Caja::create(['nombre' => 'Caja 1', 'activo' => false]);

        $this->get('/display/'.str_repeat('a', 64))->assertNotFound();
        $this->get(route('display.cliente', $inactiva->display_token))->assertNotFound();
    }

    public function test_regenerar_el_token_invalida_la_url_anterior(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $anterior = $caja->display_token;

        $caja->regenerarDisplayToken();

        $this->get('/display/'.$anterior)->assertNotFound();
        $this->get(route('display.cliente', $caja->refresh()->display_token))->assertOk();
    }

    public function test_si_la_caja_se_desactiva_con_el_display_abierto_deja_de_mostrar_datos(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $this->publicar($caja, []);

        $componente = Livewire::test(DisplayCliente::class, ['token' => $caja->display_token])
            ->assertSee('Arroz La Garza 5lb');

        $caja->update(['activo' => false]);

        $componente->call('$refresh')
            ->assertDontSee('Arroz La Garza 5lb')
            ->assertSee('Display no disponible');
    }

    public function test_tras_cobrar_muestra_el_mensaje_personalizado_y_luego_vuelve_a_espera(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1', 'mensaje_display' => '¡Vuelva pronto!']);
        $this->publicar($caja, ['estado' => 'gracias', 'items' => []]);

        $componente = Livewire::test(DisplayCliente::class, ['token' => $caja->display_token])
            ->assertSee('¡Vuelva pronto!');

        $this->travel(PuntoDeVentaTouch::SEGUNDOS_GRACIAS + 2)->seconds();

        $componente->call('$refresh')
            ->assertDontSee('¡Vuelva pronto!')
            ->assertSee('Bienvenido');
    }

    public function test_el_token_no_se_puede_cambiar_desde_el_navegador(): void
    {
        $caja = Caja::create(['nombre' => 'Caja 1']);
        $otra = Caja::create(['nombre' => 'Caja 2']);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(DisplayCliente::class, ['token' => $caja->display_token])
            ->set('token', $otra->display_token);
    }
}
