<?php

namespace Tests\Feature;

use App\Enums\CategoriaSabor;
use App\Enums\EstadoFiscal;
use App\Enums\FormaPago;
use App\Enums\PerfilEmpresa;
use App\Enums\TipoComprobante;
use App\Filament\Pages\PosHeladeria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\PresentacionHelado;
use App\Models\SaborHelado;
use App\Models\SecuenciaNcf;
use App\Models\ServicioLavanderia;
use App\Models\TicketLavanderia;
use App\Models\TipoPrenda;
use App\Models\User;
use App\Models\Venta;
use App\Services\Dgii\EcfBuilder;
use App\Services\HeladeriaService;
use App\Services\RolesEmpresaService;
use App\Services\TicketLavanderiaService;
use App\Support\ComprobanteVenta;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Lavandería y heladería respetan la configuración fiscal de la empresa: si cobra ITBIS, si el
 * precio lo incluye, si permite vender sin comprobante y si emite e-CF.
 */
class VerticalesComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private User $usuario;

    private function preparar(array $config = [], bool $usaEcf = true, PerfilEmpresa $perfil = PerfilEmpresa::LAVANDERIA): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->empresa = Empresa::factory()->create(['perfil' => $perfil, 'usa_ecf' => $usaEcf]);
        app(RolesEmpresaService::class)->sembrarRolesBase($this->empresa);
        Filament::setTenant($this->empresa, isQuiet: true);
        setPermissionsTeamId($this->empresa->id);

        $this->empresa->config()->update($config + [
            'permite_ventas_sin_comprobante' => true,
            'dgii_base_url' => 'https://pac.test/api/v1',
            'dgii_api_key' => 'clave-de-prueba',
        ]);
        $this->empresa = $this->empresa->fresh();

        $this->usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($this->usuario, $this->empresa);
        $this->actingAs($this->usuario);
    }

    private function secuencia(TipoComprobante $tipo, string $prefijo): void
    {
        SecuenciaNcf::create([
            'empresa_id' => $this->empresa->id,
            'tipo_comprobante' => $tipo->value,
            'prefijo' => $prefijo,
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function ticket(array $datosVenta = [], ?int $clienteId = null, bool $servicioConItbis = true): TicketLavanderia
    {
        $prenda = TipoPrenda::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Camisa', 'activo' => true]);
        $servicio = ServicioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Lavado y Planchado',
            'precio_base' => 150,
            'aplica_itbis' => $servicioConItbis,
            'activo' => true,
        ]);

        return app(TicketLavanderiaService::class)->crear($this->empresa, $this->usuario, [
            'cliente_id' => $clienteId,
            'pagar_ahora' => true,
            'datos_venta' => $datosVenta + ['forma_pago' => FormaPago::EFECTIVO, 'monto_efectivo' => 1000],
            'detalles' => [['tipo_prenda_id' => $prenda->id, 'servicio_lavanderia_id' => $servicio->id, 'cantidad' => 3]],
        ]);
    }

    // ------------------------------------------------------------------ Opciones por configuración

    public function test_opciones_de_comprobante_siguen_la_configuracion_de_la_empresa(): void
    {
        $this->preparar();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO_FISICA, 'B02');

        $this->assertSame(
            [ComprobanteVenta::SIN_COMPROBANTE, '32', 'B02'],
            array_map('strval', array_keys(ComprobanteVenta::opciones($this->empresa))),
        );
        $this->assertSame(ComprobanteVenta::SIN_COMPROBANTE, ComprobanteVenta::porDefecto($this->empresa));

        // Sin "ventas sin comprobante" y sin e-CF: solo queda el tipo B.
        $this->empresa->update(['usa_ecf' => false]);
        $this->empresa->config()->update(['permite_ventas_sin_comprobante' => false]);
        $empresa = $this->empresa->fresh();

        $this->assertSame(['B02'], array_map('strval', array_keys(ComprobanteVenta::opciones($empresa))));
        $this->assertSame('B02', ComprobanteVenta::porDefecto($empresa));
    }

    // ------------------------------------------------------------------ Lavandería

    public function test_lavanderia_sin_elegir_comprobante_cobra_sin_ncf_si_la_empresa_lo_permite(): void
    {
        $this->preparar();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $venta = $this->ticket()->venta;

        $this->assertNull($venta->ncf);
        $this->assertSame(EstadoFiscal::NO_APLICA, $venta->estado_fiscal);
    }

    public function test_lavanderia_emite_ecf_cuando_la_empresa_no_permite_vender_sin_comprobante(): void
    {
        $this->preparar(['permite_ventas_sin_comprobante' => false]);
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $ticket = $this->ticket();
        $venta = $ticket->venta->fresh();

        $this->assertStringStartsWith('E32', $venta->ncf);
        $this->assertSame(EstadoFiscal::ACEPTADO, $venta->estado_fiscal);
        $this->assertSame((string) $ticket->total, (string) $venta->total);
    }

    public function test_lavanderia_emite_el_comprobante_que_elige_el_cajero(): void
    {
        $this->preparar();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $venta = $this->ticket(['comprobante' => '32'])->venta;

        $this->assertStringStartsWith('E32', $venta->ncf);
    }

    public function test_lavanderia_credito_fiscal_sin_cliente_avisa_en_vez_de_reventar(): void
    {
        $this->preparar();
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RNC');

        $this->ticket(['comprobante' => '31']);
    }

    public function test_lavanderia_credito_fiscal_con_cliente_con_rnc(): void
    {
        $this->preparar();
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');
        $cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Empresa Cliente SRL', 'tipo_documento' => 'rnc', 'documento' => '131234567', 'activo' => true]);

        $venta = $this->ticket(['comprobante' => '31'], $cliente->id)->venta;
        $ecf = app(EcfBuilder::class)->construir($venta->fresh());

        $this->assertStringStartsWith('E31', $venta->ncf);
        $this->assertSame('131234567', $ecf['ECF']['Encabezado']['Comprador']['RNCComprador']);
    }

    public function test_lavanderia_total_del_ticket_igual_al_de_la_venta_con_precio_que_incluye_itbis(): void
    {
        $this->preparar(['precio_incluye_itbis' => true]);
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $ticket = $this->ticket(['comprobante' => '32']);

        // 3 × 150 con ITBIS incluido: total 450, de los cuales 68.64 son ITBIS.
        $this->assertSame('450.00', (string) $ticket->total);
        $this->assertSame('68.64', (string) $ticket->itbis);
        $this->assertSame('450.00', (string) $ticket->venta->total);
    }

    public function test_lavanderia_no_cobra_itbis_si_la_empresa_no_lo_aplica(): void
    {
        $this->preparar(['aplica_itbis' => false]);

        $ticket = $this->ticket();

        $this->assertSame('450.00', (string) $ticket->total);
        $this->assertSame('0.00', (string) $ticket->itbis);
        $this->assertSame('450.00', (string) $ticket->venta->total);
    }

    public function test_lavanderia_calcula_el_vuelto_contra_el_total_con_itbis(): void
    {
        $this->preparar();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        // El formulario mandaba un vuelto calculado sin ITBIS (1000 − 450): se ignora.
        $venta = $this->ticket(['comprobante' => '32', 'vuelto' => 550])->venta->fresh();
        $ecf = app(EcfBuilder::class)->construir($venta);

        $this->assertSame('531.00', (string) $venta->total);
        $this->assertSame('469.00', (string) $venta->vuelto);
        $this->assertSame(
            [['FormaPago' => '1', 'MontoPago' => '531.00']],
            $ecf['ECF']['Encabezado']['IdDoc']['TablaFormasPago']['FormaDetalle'],
        );
    }

    public function test_el_recibo_de_lavanderia_muestra_los_datos_fiscales(): void
    {
        $this->preparar();
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');

        $ticket = $this->ticket(['comprobante' => '32']);
        // Pendiente: el recibo debe avisarlo y no llevar QR (evita depender de Imagick en el test).
        $ticket->venta->update(['estado_fiscal' => EstadoFiscal::PENDIENTE]);

        $this->get(route('lavanderia.ticket.recibo', $ticket))
            ->assertOk()
            ->assertSee($ticket->venta->ncf)
            ->assertSee('PENDIENTE DE VALIDACIÓN')
            ->assertDontSee('QR del timbre fiscal');
    }

    public function test_el_recibo_de_lavanderia_sin_comprobante_no_muestra_bloque_fiscal(): void
    {
        $this->preparar();

        $this->get(route('lavanderia.ticket.recibo', $this->ticket()))
            ->assertOk()
            ->assertDontSee('NCF:');
    }

    // ------------------------------------------------------------------ Heladería

    public function test_heladeria_total_del_carrito_igual_al_de_la_venta_con_itbis_aparte(): void
    {
        $this->preparar(perfil: PerfilEmpresa::HELADERIA);
        $items = [['tipo' => 'armado', 'descripcion' => 'Copa: Vainilla', 'precio' => 250, 'cantidad' => 1]];
        $servicio = app(HeladeriaService::class);

        $totales = $servicio->calcularTotales($items, $this->empresa);
        $venta = $servicio->facturar($items, $this->empresa, ['forma_pago' => FormaPago::EFECTIVO, 'monto_efectivo' => 300]);

        $this->assertSame('295.00', $totales['total']);
        $this->assertSame('45.00', $totales['itbis']);
        $this->assertSame('295.00', (string) $venta->total);
        $this->assertSame('5.00', (string) $venta->vuelto);
    }

    public function test_heladeria_total_sin_itbis_si_la_empresa_no_lo_aplica_o_ya_lo_incluye(): void
    {
        $this->preparar(['aplica_itbis' => false], perfil: PerfilEmpresa::HELADERIA);
        $items = [['tipo' => 'armado', 'descripcion' => 'Copa: Vainilla', 'precio' => 250, 'cantidad' => 2]];
        $servicio = app(HeladeriaService::class);

        $this->assertSame('500.00', $servicio->calcularTotales($items, $this->empresa)['total']);
        $this->assertSame('500.00', (string) $servicio->facturar($items, $this->empresa, ['forma_pago' => FormaPago::TARJETA])->total);

        $this->empresa->config()->update(['aplica_itbis' => true, 'precio_incluye_itbis' => true]);
        $empresa = $this->empresa->fresh();

        $this->assertSame('500.00', $servicio->calcularTotales($items, $empresa)['total']);
        $this->assertSame('76.27', $servicio->calcularTotales($items, $empresa)['itbis']);
        $this->assertSame('500.00', (string) $servicio->facturar($items, $empresa, ['forma_pago' => FormaPago::TARJETA])->total);
    }

    public function test_heladeria_dos_helados_que_empiezan_igual_no_comparten_descripcion(): void
    {
        $this->preparar(perfil: PerfilEmpresa::HELADERIA);
        $servicio = app(HeladeriaService::class);
        $uno = 'Copa Grande: Vainilla, Chocolate, Fresa + Chispas, Sirope';
        $dos = 'Copa Grande: Vainilla, Chocolate, Menta + Gomitas';

        $ventaUno = $servicio->facturar([['tipo' => 'armado', 'descripcion' => $uno, 'precio' => 250, 'cantidad' => 1]], $this->empresa, ['forma_pago' => FormaPago::TARJETA]);
        $ventaDos = $servicio->facturar([['tipo' => 'armado', 'descripcion' => $dos, 'precio' => 200, 'cantidad' => 1]], $this->empresa, ['forma_pago' => FormaPago::TARJETA]);

        $this->assertStringContainsString('Sirope', $ventaUno->detalles->first()->descripcion);
        $this->assertStringContainsString('Menta', $ventaDos->detalles->first()->descripcion);
    }

    public function test_heladeria_emite_ecf_y_recorta_el_nombre_del_item_a_80(): void
    {
        $this->preparar(['permite_ventas_sin_comprobante' => false], perfil: PerfilEmpresa::HELADERIA);
        $this->secuencia(TipoComprobante::FACTURA_CONSUMO, 'E32');
        $largo = 'Copa Grande: Vainilla, Chocolate, Fresa + Chispas, Sirope de caramelo, Oreo, Maní, Coco';

        $venta = app(HeladeriaService::class)->facturar(
            [['tipo' => 'armado', 'descripcion' => $largo, 'precio' => 250, 'cantidad' => 1]],
            $this->empresa,
            ['forma_pago' => FormaPago::EFECTIVO, 'monto_efectivo' => 500],
        );
        $ecf = app(EcfBuilder::class)->construir($venta->fresh());

        $this->assertStringStartsWith('E32', $venta->ncf);
        $this->assertLessThanOrEqual(80, mb_strlen($ecf['ECF']['DetallesItems']['Item'][0]['NombreItem']));
        $this->assertSame('295.00', $ecf['ECF']['Encabezado']['IdDoc']['TablaFormasPago']['FormaDetalle'][0]['MontoPago']);
    }

    public function test_pos_heladeria_cobra_con_ecf_cliente_y_abre_el_ticket(): void
    {
        $this->preparar(perfil: PerfilEmpresa::HELADERIA);
        $this->secuencia(TipoComprobante::FACTURA_CREDITO_FISCAL, 'E31');
        $cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Empresa Cliente SRL', 'tipo_documento' => 'rnc', 'documento' => '131234567', 'activo' => true]);
        $ajeno = Cliente::create(['empresa_id' => Empresa::factory()->create()->id, 'nombre' => 'Empresa Ajena SRL', 'tipo_documento' => 'rnc', 'documento' => '101999999', 'activo' => true]);

        // El carrito se arma desde el catálogo: el POS ya no acepta ítems con precio puesto a mano.
        $copa = PresentacionHelado::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Copa', 'icono' => '🍨', 'scoops_incluidos' => 1, 'toppings_incluidos' => 0, 'precio' => 250, 'activo' => true, 'orden' => 1]);
        $vainilla = SaborHelado::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Vainilla', 'color_hex' => '#f5e6c8', 'categoria' => CategoriaSabor::cases()[0], 'premium' => false, 'disponible' => true, 'activo' => true, 'orden' => 1]);

        $pagina = Livewire::test(PosHeladeria::class)
            ->assertSet('comprobante', ComprobanteVenta::SIN_COMPROBANTE)
            ->call('seleccionarPresentacion', $copa->id)
            ->call('toggleSabor', $vainilla->id)
            ->call('irAToppings')
            ->call('agregarArmadoAlCarrito')
            ->assertSet('carrito.0.precio', 250.0)
            ->assertSee('295.00')
            ->set('comprobante', '31')
            ->set('buscarCliente', 'Empresa')
            ->assertSee('Empresa Cliente SRL')
            ->assertDontSee('Empresa Ajena SRL')
            // Un id de otra empresa (client-controllable) no se acepta.
            ->call('seleccionarCliente', $ajeno->id)
            ->assertSet('clienteId', null)
            ->call('seleccionarCliente', $cliente->id)
            ->assertSet('clienteId', $cliente->id)
            // Efectivo que cubre el precio pero no el ITBIS: no cobra.
            ->call('cobrar', 250.0)
            ->assertNotDispatched('venta-cobrada')
            ->call('cobrar', 300.0)
            ->assertDispatched('venta-cobrada')
            ->assertSet('carrito', [])
            ->assertSet('clienteId', null);

        $venta = Venta::where('empresa_id', $this->empresa->id)->latest('id')->first();

        $this->assertStringStartsWith('E31', $venta->ncf);
        $this->assertSame($cliente->id, $venta->cliente_id);
        $this->assertSame('295.00', (string) $venta->total);
        $this->assertSame('5.00', (string) $venta->vuelto);
        $pagina->assertDispatched('venta-cobrada', ticketUrl: route('ventas.ticket', $venta));
    }

    /** Mismo servicio en dos prendas con precios distintos: cada línea conserva su precio. */
    public function test_lavanderia_mismo_servicio_con_precios_distintos_no_se_consolida_al_primer_precio(): void
    {
        $this->preparar();
        $camisa = TipoPrenda::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Camisa', 'activo' => true]);
        $pantalon = TipoPrenda::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Pantalón', 'activo' => true]);
        $servicio = ServicioLavanderia::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Lavado y planchado', 'precio_base' => 250, 'aplica_itbis' => true, 'activo' => true]);

        $ticket = app(TicketLavanderiaService::class)->crear($this->empresa, $this->usuario, [
            'pagar_ahora' => true,
            'datos_venta' => ['forma_pago' => FormaPago::TARJETA],
            'detalles' => [
                ['tipo_prenda_id' => $camisa->id, 'servicio_lavanderia_id' => $servicio->id, 'cantidad' => 10, 'precio_unitario' => 150],
                ['tipo_prenda_id' => $pantalon->id, 'servicio_lavanderia_id' => $servicio->id, 'cantidad' => 10, 'precio_unitario' => 180],
            ],
        ]);

        // (1500 + 1800) + 18 % = 3894.
        $this->assertSame('3894.00', (string) $ticket->total);
        $this->assertSame('3894.00', (string) $ticket->venta->total);
        $this->assertCount(2, $ticket->venta->detalles);
    }
}
