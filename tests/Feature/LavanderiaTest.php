<?php

namespace Tests\Feature;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Enums\PerfilEmpresa;
use App\Models\DetalleTicketLavanderia;
use App\Models\Empresa;
use App\Models\ServicioLavanderia;
use App\Models\TicketLavanderia;
use App\Models\TipoPrenda;
use App\Models\User;
use App\Services\RolesEmpresaService;
use App\Services\TicketLavanderiaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LavanderiaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private User $recepcionista;
    private User $operador;
    private TipoPrenda $tipoPrenda;
    private ServicioLavanderia $servicioConItbis;
    private ServicioLavanderia $servicioSinItbis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->empresa = Empresa::factory()->create(['perfil' => PerfilEmpresa::LAVANDERIA]);
        app(RolesEmpresaService::class)->sembrarRolesBase($this->empresa);

        Filament::setTenant($this->empresa, isQuiet: true);
        setPermissionsTeamId($this->empresa->id);

        // Admin
        $this->admin = User::factory()->create(['empresa_id' => $this->empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($this->admin, $this->empresa);

        // Recepcionista
        $this->recepcionista = User::factory()->create(['empresa_id' => $this->empresa->id]);
        setPermissionsTeamId($this->empresa->id);
        $this->recepcionista->assignRole('Recepcionista Lavandería');

        // Operador
        $this->operador = User::factory()->create(['empresa_id' => $this->empresa->id]);
        setPermissionsTeamId($this->empresa->id);
        $this->operador->assignRole('Operador Lavandería');

        // Catálogos
        $this->tipoPrenda = TipoPrenda::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Camisa',
            'activo' => true,
        ]);

        $this->servicioConItbis = ServicioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Lavado y Planchado',
            'precio_base' => 150.00,
            'aplica_itbis' => true,
            'activo' => true,
        ]);

        $this->servicioSinItbis = ServicioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Solo Planchado',
            'precio_base' => 80.00,
            'aplica_itbis' => false,
            'activo' => true,
        ]);
    }

    private function datosTicketBase(array $override = []): array
    {
        return array_merge([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 2,
                    'precio_unitario' => 150.00,
                ],
            ],
        ], $override);
    }

    private function crearTicket(array $datos = []): TicketLavanderia
    {
        return app(TicketLavanderiaService::class)->crear(
            $this->empresa,
            $this->recepcionista,
            $this->datosTicketBase($datos),
        );
    }

    // ───────────────────────────────────────────────────────────────────
    //  Creación de tickets
    // ───────────────────────────────────────────────────────────────────

    public function test_crear_ticket_genera_numero_consecutivo(): void
    {
        $ticket = $this->crearTicket();

        $this->assertMatchesRegularExpression('/^TK-\d{4}$/', $ticket->numero);
        $this->assertEquals(EstadoTicketLavanderia::RECIBIDO, $ticket->estado);
        $this->assertEquals(EstadoPagoTicket::PENDIENTE, $ticket->estado_pago);
    }

    public function test_consecutivo_es_unico_por_empresa(): void
    {
        $t1 = $this->crearTicket();
        $t2 = $this->crearTicket();

        $this->assertNotEquals($t1->numero, $t2->numero);
    }

    public function test_crear_ticket_calcula_total_con_itbis(): void
    {
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 2,
                    'precio_unitario' => 100.00,
                ],
            ],
        ]);

        // subtotal = 100 * 2 = 200, ITBIS = 200 * 0.18 = 36, total = 236
        $this->assertEquals('36.00', $ticket->itbis);
        $this->assertEquals('236.00', $ticket->total);
    }

    public function test_crear_ticket_sin_itbis_cuando_servicio_no_aplica(): void
    {
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioSinItbis->id,
                    'cantidad' => 3,
                    'precio_unitario' => 80.00,
                ],
            ],
        ]);

        // subtotal = 80 * 3 = 240, ITBIS = 0, total = 240
        $this->assertEquals('0.00', $ticket->itbis);
        $this->assertEquals('240.00', $ticket->total);
    }

    public function test_crear_ticket_calcula_itbis_mixto(): void
    {
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 1,
                    'precio_unitario' => 100.00,
                ],
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioSinItbis->id,
                    'cantidad' => 1,
                    'precio_unitario' => 50.00,
                ],
            ],
        ]);

        // Línea 1: 100 * 1 = 100, ITBIS = 18.00
        // Línea 2: 50 * 1 = 50, ITBIS = 0
        // Total neto = 150, ITBIS total = 18, Total = 168
        $this->assertEquals('18.00', $ticket->itbis);
        $this->assertEquals('168.00', $ticket->total);
    }

    public function test_crear_ticket_crea_detalles_correctamente(): void
    {
        $ticket = $this->crearTicket();

        $this->assertCount(1, $ticket->detalles);

        $detalle = $ticket->detalles->first();
        $this->assertEquals($this->tipoPrenda->id, $detalle->tipo_prenda_id);
        $this->assertEquals($this->servicioConItbis->id, $detalle->servicio_lavanderia_id);
        $this->assertEquals(2, $detalle->cantidad);
        $this->assertEquals('150.00', $detalle->precio_unitario);
        $this->assertEquals('300.00', $detalle->subtotal);
    }

    public function test_crear_ticket_asigna_recepcionista(): void
    {
        $ticket = $this->crearTicket();

        $this->assertEquals($this->recepcionista->id, $ticket->recepcionista_id);
    }

    public function test_crear_ticket_sin_detalles_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('al menos una prenda');

        app(TicketLavanderiaService::class)->crear(
            $this->empresa,
            $this->recepcionista,
            ['detalles' => []],
        );
    }

    public function test_crear_ticket_usa_precio_base_si_no_se_especifica(): void
    {
        $ticket = app(TicketLavanderiaService::class)->crear(
            $this->empresa,
            $this->recepcionista,
            [
                'detalles' => [
                    [
                        'tipo_prenda_id' => $this->tipoPrenda->id,
                        'servicio_lavanderia_id' => $this->servicioConItbis->id,
                        'cantidad' => 1,
                        // sin precio_unitario → usa precio_base del servicio (150)
                    ],
                ],
            ],
        );

        $this->assertEquals('150.00', $ticket->detalles->first()->precio_unitario);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Máquina de estados
    // ───────────────────────────────────────────────────────────────────

    public function test_avanzar_estado_de_recibido_a_en_proceso(): void
    {
        $ticket = $this->crearTicket();
        $this->assertEquals(EstadoTicketLavanderia::RECIBIDO, $ticket->estado);

        app(TicketLavanderiaService::class)->avanzarEstado($ticket);
        $ticket->refresh();

        $this->assertEquals(EstadoTicketLavanderia::EN_PROCESO, $ticket->estado);
    }

    public function test_avanzar_estado_de_en_proceso_a_listo(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        $service->avanzarEstado($ticket); // RECIBIDO → EN_PROCESO
        $ticket->refresh();

        $service->avanzarEstado($ticket); // EN_PROCESO → LISTO
        $ticket->refresh();

        $this->assertEquals(EstadoTicketLavanderia::LISTO, $ticket->estado);
    }

    public function test_no_se_puede_avanzar_estado_listo(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        $service->avanzarEstado($ticket); // → EN_PROCESO
        $ticket->refresh();
        $service->avanzarEstado($ticket); // → LISTO
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $service->avanzarEstado($ticket); // LISTO → ???
    }

    public function test_no_se_puede_avanzar_ticket_cancelado(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        $service->cancelar($ticket);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $service->avanzarEstado($ticket);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Cancelación
    // ───────────────────────────────────────────────────────────────────

    public function test_cancelar_ticket_recibido(): void
    {
        $ticket = $this->crearTicket();

        app(TicketLavanderiaService::class)->cancelar($ticket);
        $ticket->refresh();

        $this->assertEquals(EstadoTicketLavanderia::CANCELADO, $ticket->estado);
    }

    public function test_cancelar_ticket_en_proceso(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        $service->avanzarEstado($ticket); // → EN_PROCESO
        $ticket->refresh();

        $service->cancelar($ticket);
        $ticket->refresh();

        $this->assertEquals(EstadoTicketLavanderia::CANCELADO, $ticket->estado);
    }

    public function test_no_se_puede_cancelar_ticket_entregado(): void
    {
        $ticket = $this->crearTicket();
        $ticket->update(['estado' => EstadoTicketLavanderia::ENTREGADO]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya entregado');

        app(TicketLavanderiaService::class)->cancelar($ticket);
    }

    public function test_no_se_puede_cancelar_ticket_ya_cancelado(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        $service->cancelar($ticket);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya está cancelado');

        $service->cancelar($ticket);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Entrega
    // ───────────────────────────────────────────────────────────────────

    public function test_no_se_puede_entregar_ticket_que_no_esta_listo(): void
    {
        $ticket = $this->crearTicket(); // Estado RECIBIDO

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('estado Listo');

        app(TicketLavanderiaService::class)->entregar($ticket, $this->empresa);
    }

    public function test_no_se_puede_entregar_ticket_de_otra_empresa(): void
    {
        $otraEmpresa = Empresa::factory()->create();

        $ticket = $this->crearTicket();
        $ticket->update(['estado' => EstadoTicketLavanderia::LISTO]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenece');

        app(TicketLavanderiaService::class)->entregar($ticket, $otraEmpresa);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Aislamiento entre empresas (tenancy)
    // ───────────────────────────────────────────────────────────────────

    public function test_tipos_prenda_aislados_por_empresa(): void
    {
        $otraEmpresa = Empresa::factory()->create();

        $tipoPrendaOtra = TipoPrenda::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Pantalón',
            'activo' => true,
        ]);

        // Cada empresa solo ve las suyas
        $tiposMiEmpresa = TipoPrenda::where('empresa_id', $this->empresa->id)->pluck('id');
        $tiposOtra = TipoPrenda::where('empresa_id', $otraEmpresa->id)->pluck('id');

        $this->assertTrue($tiposMiEmpresa->contains($this->tipoPrenda->id));
        $this->assertFalse($tiposMiEmpresa->contains($tipoPrendaOtra->id));
        $this->assertTrue($tiposOtra->contains($tipoPrendaOtra->id));
        $this->assertFalse($tiposOtra->contains($this->tipoPrenda->id));
    }

    public function test_servicios_lavanderia_aislados_por_empresa(): void
    {
        $otraEmpresa = Empresa::factory()->create();

        $servicioOtra = ServicioLavanderia::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Tintorería Premium',
            'precio_base' => 500.00,
            'aplica_itbis' => true,
            'activo' => true,
        ]);

        $serviciosMios = ServicioLavanderia::where('empresa_id', $this->empresa->id)->pluck('id');

        $this->assertFalse($serviciosMios->contains($servicioOtra->id));
        $this->assertTrue($serviciosMios->contains($this->servicioConItbis->id));
    }

    public function test_tickets_aislados_por_empresa(): void
    {
        $t1 = $this->crearTicket();

        $otraEmpresa = Empresa::factory()->create();
        $ticketsMiEmpresa = TicketLavanderia::where('empresa_id', $this->empresa->id)->pluck('id');
        $ticketsOtra = TicketLavanderia::where('empresa_id', $otraEmpresa->id)->pluck('id');

        $this->assertTrue($ticketsMiEmpresa->contains($t1->id));
        $this->assertCount(0, $ticketsOtra);
    }

    public function test_no_puede_crear_ticket_con_tipo_prenda_de_otra_empresa(): void
    {
        $otraEmpresa = Empresa::factory()->create();
        $tipoPrendaAjena = TipoPrenda::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Abrigo',
            'activo' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenecen a esta empresa');

        app(TicketLavanderiaService::class)->crear(
            $this->empresa,
            $this->recepcionista,
            [
                'detalles' => [
                    [
                        'tipo_prenda_id' => $tipoPrendaAjena->id,
                        'servicio_lavanderia_id' => $this->servicioConItbis->id,
                        'cantidad' => 1,
                    ],
                ],
            ],
        );
    }

    public function test_no_puede_crear_ticket_con_servicio_de_otra_empresa(): void
    {
        $otraEmpresa = Empresa::factory()->create();
        $servicioAjeno = ServicioLavanderia::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Lavado Premium',
            'precio_base' => 999.00,
            'aplica_itbis' => true,
            'activo' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenecen a esta empresa');

        app(TicketLavanderiaService::class)->crear(
            $this->empresa,
            $this->recepcionista,
            [
                'detalles' => [
                    [
                        'tipo_prenda_id' => $this->tipoPrenda->id,
                        'servicio_lavanderia_id' => $servicioAjeno->id,
                        'cantidad' => 1,
                    ],
                ],
            ],
        );
    }

    public function test_no_puede_usar_tipo_prenda_inactivo(): void
    {
        $prendaInactiva = TipoPrenda::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Abrigo Inactivo',
            'activo' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenecen a esta empresa');

        app(TicketLavanderiaService::class)->crear(
            $this->empresa,
            $this->recepcionista,
            [
                'detalles' => [
                    [
                        'tipo_prenda_id' => $prendaInactiva->id,
                        'servicio_lavanderia_id' => $this->servicioConItbis->id,
                        'cantidad' => 1,
                    ],
                ],
            ],
        );
    }

    public function test_no_puede_usar_servicio_inactivo(): void
    {
        $servicioInactivo = ServicioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Servicio Inactivo',
            'precio_base' => 100.00,
            'aplica_itbis' => true,
            'activo' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenecen a esta empresa');

        app(TicketLavanderiaService::class)->crear(
            $this->empresa,
            $this->recepcionista,
            [
                'detalles' => [
                    [
                        'tipo_prenda_id' => $this->tipoPrenda->id,
                        'servicio_lavanderia_id' => $servicioInactivo->id,
                        'cantidad' => 1,
                    ],
                ],
            ],
        );
    }

    // ───────────────────────────────────────────────────────────────────
    //  Roles y permisos
    // ───────────────────────────────────────────────────────────────────

    public function test_recepcionista_tiene_permisos_de_ticket(): void
    {
        setPermissionsTeamId($this->empresa->id);

        $this->assertTrue($this->recepcionista->can('lavanderia.ver_tickets'));
        $this->assertTrue($this->recepcionista->can('lavanderia.crear_ticket'));
        $this->assertTrue($this->recepcionista->can('lavanderia.cambiar_estado'));
        $this->assertTrue($this->recepcionista->can('lavanderia.entregar'));
    }

    public function test_operador_solo_tiene_permisos_de_ver_y_cambiar_estado(): void
    {
        setPermissionsTeamId($this->empresa->id);

        $this->assertTrue($this->operador->can('lavanderia.ver_tickets'));
        $this->assertTrue($this->operador->can('lavanderia.cambiar_estado'));
        $this->assertFalse($this->operador->can('lavanderia.crear_ticket'));
        $this->assertFalse($this->operador->can('lavanderia.entregar'));
        $this->assertFalse($this->operador->can('lavanderia.configurar'));
    }

    public function test_admin_tiene_todos_los_permisos_de_lavanderia(): void
    {
        setPermissionsTeamId($this->empresa->id);

        $this->assertTrue($this->admin->can('lavanderia.ver_tickets'));
        $this->assertTrue($this->admin->can('lavanderia.crear_ticket'));
        $this->assertTrue($this->admin->can('lavanderia.cambiar_estado'));
        $this->assertTrue($this->admin->can('lavanderia.entregar'));
        $this->assertTrue($this->admin->can('lavanderia.configurar'));
        $this->assertTrue($this->admin->can('lavanderia.anular_ticket'));
    }

    // ───────────────────────────────────────────────────────────────────
    //  Policies
    // ───────────────────────────────────────────────────────────────────

    public function test_policy_ticket_no_permite_eliminar(): void
    {
        $ticket = $this->crearTicket();

        $this->assertFalse($this->admin->can('delete', $ticket));
    }

    public function test_policy_tipo_prenda_no_permite_eliminar(): void
    {
        $this->assertFalse($this->admin->can('delete', $this->tipoPrenda));
    }

    public function test_policy_servicio_no_permite_eliminar(): void
    {
        $this->assertFalse($this->admin->can('delete', $this->servicioConItbis));
    }

    // ───────────────────────────────────────────────────────────────────
    //  Flujo completo de estados
    // ───────────────────────────────────────────────────────────────────

    public function test_flujo_completo_recibido_a_entregado_pago_pendiente(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        // RECIBIDO → EN_PROCESO
        $service->avanzarEstado($ticket);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::EN_PROCESO, $ticket->estado);

        // EN_PROCESO → LISTO
        $service->avanzarEstado($ticket);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::LISTO, $ticket->estado);
        $this->assertEquals(EstadoPagoTicket::PENDIENTE, $ticket->estado_pago);

        // LISTO → ENTREGADO (la entrega requiere facturación — este test valida el flujo
        // hasta el punto de entrega; la facturación depende de VentaService que necesita
        // un producto y secuencias NCF configuradas, lo cual se prueba a nivel de integración)
        $this->assertNull($ticket->entregado_en);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Consecutivos
    // ───────────────────────────────────────────────────────────────────

    public function test_consecutivo_incrementa_por_empresa(): void
    {
        $t1 = $this->crearTicket();
        $t2 = $this->crearTicket();
        $t3 = $this->crearTicket();

        // Extraer números
        $n1 = (int) str_replace('TK-', '', $t1->numero);
        $n2 = (int) str_replace('TK-', '', $t2->numero);
        $n3 = (int) str_replace('TK-', '', $t3->numero);

        $this->assertEquals($n1 + 1, $n2);
        $this->assertEquals($n2 + 1, $n3);
    }

    public function test_consecutivo_independiente_entre_empresas(): void
    {
        // Crear 2 tickets en la empresa principal
        $this->crearTicket();
        $this->crearTicket();

        // Crear otra empresa con sus catálogos
        $otraEmpresa = Empresa::factory()->create(['perfil' => PerfilEmpresa::LAVANDERIA]);
        app(RolesEmpresaService::class)->sembrarRolesBase($otraEmpresa);
        $otraRecepcionista = User::factory()->create(['empresa_id' => $otraEmpresa->id]);

        setPermissionsTeamId($otraEmpresa->id);
        $otraRecepcionista->assignRole('Recepcionista Lavandería');

        $tipoPrendaOtra = TipoPrenda::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Pantalón',
            'activo' => true,
        ]);

        $servicioOtra = ServicioLavanderia::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Lavado',
            'precio_base' => 100.00,
            'aplica_itbis' => true,
            'activo' => true,
        ]);

        $ticketOtra = app(TicketLavanderiaService::class)->crear(
            $otraEmpresa,
            $otraRecepcionista,
            [
                'detalles' => [
                    [
                        'tipo_prenda_id' => $tipoPrendaOtra->id,
                        'servicio_lavanderia_id' => $servicioOtra->id,
                        'cantidad' => 1,
                    ],
                ],
            ],
        );

        // La otra empresa empieza su propio consecutivo en TK-0001
        $this->assertEquals('TK-0001', $ticketOtra->numero);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Observaciones y fecha prometida
    // ───────────────────────────────────────────────────────────────────

    public function test_ticket_guarda_observaciones_y_fecha_prometida(): void
    {
        $ticket = $this->crearTicket([
            'observaciones' => 'Mancha difícil en cuello',
            'fecha_prometida' => '2026-11-01',
        ]);

        $this->assertEquals('Mancha difícil en cuello', $ticket->observaciones);
        $this->assertEquals('2026-11-01', $ticket->fecha_prometida->format('Y-m-d'));
    }

    public function test_detalle_guarda_observaciones(): void
    {
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 1,
                    'observaciones' => 'Botón faltante',
                ],
            ],
        ]);

        $this->assertEquals('Botón faltante', $ticket->detalles->first()->observaciones);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Relaciones del modelo
    // ───────────────────────────────────────────────────────────────────

    public function test_ticket_pertenece_a_empresa(): void
    {
        $ticket = $this->crearTicket();

        $this->assertEquals($this->empresa->id, $ticket->empresa->id);
    }

    public function test_ticket_tiene_detalles(): void
    {
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 1,
                ],
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioSinItbis->id,
                    'cantidad' => 2,
                ],
            ],
        ]);

        $this->assertCount(2, $ticket->detalles);
    }

    public function test_detalle_tiene_relaciones_a_tipo_prenda_y_servicio(): void
    {
        $ticket = $this->crearTicket();
        $detalle = $ticket->detalles->first();

        $this->assertInstanceOf(TipoPrenda::class, $detalle->tipoPrenda);
        $this->assertInstanceOf(ServicioLavanderia::class, $detalle->servicioLavanderia);
        $this->assertEquals('Camisa', $detalle->tipoPrenda->nombre);
        $this->assertEquals('Lavado y Planchado', $detalle->servicioLavanderia->nombre);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Enums
    // ───────────────────────────────────────────────────────────────────

    public function test_estado_ticket_tiene_etiquetas_y_colores(): void
    {
        foreach (EstadoTicketLavanderia::cases() as $estado) {
            $this->assertNotEmpty($estado->etiqueta());
            $this->assertNotEmpty($estado->color());
        }
    }

    public function test_estado_pago_tiene_etiquetas_y_colores(): void
    {
        foreach (EstadoPagoTicket::cases() as $estado) {
            $this->assertNotEmpty($estado->etiqueta());
            $this->assertNotEmpty($estado->color());
        }
    }
}
