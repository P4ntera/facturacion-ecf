<?php

namespace Tests\Feature;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Enums\PerfilEmpresa;
use App\Models\DetalleTicketLavanderia;
use App\Models\Empresa;
use App\Models\PrecioLavanderia;
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

    public function test_avanzar_estado_de_recibido_a_listo(): void
    {
        $ticket = $this->crearTicket();
        $this->assertEquals(EstadoTicketLavanderia::RECIBIDO, $ticket->estado);

        app(TicketLavanderiaService::class)->avanzarEstado($ticket);
        $ticket->refresh();

        $this->assertEquals(EstadoTicketLavanderia::LISTO, $ticket->estado);
    }

    public function test_avanzar_estado_de_en_proceso_a_listo_compatibilidad(): void
    {
        // Tickets antiguos que quedaron en EN_PROCESO deben poder avanzar a LISTO.
        $ticket = $this->crearTicket();
        $ticket->update(['estado' => EstadoTicketLavanderia::EN_PROCESO]);
        $ticket->refresh();

        app(TicketLavanderiaService::class)->avanzarEstado($ticket);
        $ticket->refresh();

        $this->assertEquals(EstadoTicketLavanderia::LISTO, $ticket->estado);
    }

    public function test_no_se_puede_avanzar_estado_listo(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

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

    public function test_cancelar_ticket_listo(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        $service->avanzarEstado($ticket); // → LISTO
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
    //  Cobro (sin entrega)
    // ───────────────────────────────────────────────────────────────────

    public function test_cobrar_ticket_pendiente(): void
    {
        $ticket = $this->crearTicket();
        $this->assertEquals(EstadoPagoTicket::PENDIENTE, $ticket->estado_pago);

        // total = 2 × 150 = 300 + 54 ITBIS = 354
        $venta = app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 354.00,
            'user_id' => $this->admin->id,
        ]);

        $ticket->refresh();
        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);
        $this->assertNotNull($ticket->venta_id);
        $this->assertEquals($venta->id, $ticket->venta_id);
        // El estado de flujo NO cambia al cobrar.
        $this->assertEquals(EstadoTicketLavanderia::RECIBIDO, $ticket->estado);
    }

    public function test_no_se_puede_cobrar_ticket_ya_pagado(): void
    {
        $ticket = $this->crearTicket();
        app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 354.00,
            'user_id' => $this->admin->id,
        ]);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya fue cobrado');

        app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 354.00,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_no_se_puede_cobrar_ticket_cancelado(): void
    {
        $ticket = $this->crearTicket();
        app(TicketLavanderiaService::class)->cancelar($ticket);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ticket cancelado');

        app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa);
    }

    public function test_no_se_puede_cobrar_ticket_de_otra_empresa(): void
    {
        $otraEmpresa = Empresa::factory()->create();
        $ticket = $this->crearTicket();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenece');

        app(TicketLavanderiaService::class)->cobrar($ticket, $otraEmpresa);
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

    public function test_flujo_completo_recibido_a_listo_pago_pendiente(): void
    {
        $ticket = $this->crearTicket();
        $service = app(TicketLavanderiaService::class);

        // RECIBIDO → LISTO (flujo simplificado)
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
        $fechaPrometida = now()->addHours(5)->format('Y-m-d H:i:s');

        $ticket = $this->crearTicket([
            'observaciones' => 'Mancha difícil en cuello',
            'fecha_prometida' => $fechaPrometida,
        ]);

        $this->assertEquals('Mancha difícil en cuello', $ticket->observaciones);
        $this->assertEquals(
            now()->addHours(5)->format('Y-m-d H'),
            $ticket->fecha_prometida->format('Y-m-d H'),
        );
    }

    public function test_fecha_prometida_menor_a_dos_horas_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La fecha prometida debe ser al menos 2 horas');

        $this->crearTicket([
            'fecha_prometida' => now()->addHour()->format('Y-m-d H:i:s'),
        ]);
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

    // ───────────────────────────────────────────────────────────────────
    //  Matriz de precios (prenda × servicio)
    // ───────────────────────────────────────────────────────────────────

    public function test_precio_matriz_se_usa_cuando_existe(): void
    {
        // Crear precio en la matriz: Camisa + Lavado y Planchado = 120
        PrecioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'tipo_prenda_id' => $this->tipoPrenda->id,
            'servicio_lavanderia_id' => $this->servicioConItbis->id,
            'precio' => 120.00,
        ]);

        // Crear ticket SIN precio manual → debe usar el de la matriz (120), no el base (150)
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 1,
                    // sin precio_unitario → cae a la matriz
                ],
            ],
        ]);

        $detalle = $ticket->detalles->first();
        $this->assertEquals('120.00', $detalle->precio_unitario);
        // subtotal = 120, ITBIS = 21.60, total = 141.60
        $this->assertEquals('120.00', $detalle->subtotal);
        $this->assertEquals('21.60', $ticket->itbis);
        $this->assertEquals('141.60', $ticket->total);
    }

    public function test_precio_manual_tiene_prioridad_sobre_matriz(): void
    {
        PrecioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'tipo_prenda_id' => $this->tipoPrenda->id,
            'servicio_lavanderia_id' => $this->servicioConItbis->id,
            'precio' => 120.00,
        ]);

        // Precio manual = 200 → debe ignorar la matriz (120) y el base (150)
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 1,
                    'precio_unitario' => 200.00,
                ],
            ],
        ]);

        $detalle = $ticket->detalles->first();
        $this->assertEquals('200.00', $detalle->precio_unitario);
    }

    public function test_sin_precio_manual_ni_matriz_usa_precio_base_del_servicio(): void
    {
        // No hay entrada en la matriz → debe usar precio_base = 150
        $ticket = $this->crearTicket([
            'detalles' => [
                [
                    'tipo_prenda_id' => $this->tipoPrenda->id,
                    'servicio_lavanderia_id' => $this->servicioConItbis->id,
                    'cantidad' => 1,
                    // sin precio_unitario y sin entrada en la matriz
                ],
            ],
        ]);

        $detalle = $ticket->detalles->first();
        $this->assertEquals('150.00', $detalle->precio_unitario);
    }

    public function test_precio_matriz_respeta_aislamiento_entre_empresas(): void
    {
        $otraEmpresa = Empresa::factory()->create(['perfil' => PerfilEmpresa::LAVANDERIA]);

        $tipoPrendaOtra = TipoPrenda::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Camisa',
            'activo' => true,
        ]);

        $servicioOtra = ServicioLavanderia::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Lavado',
            'precio_base' => 100.00,
            'aplica_itbis' => true,
            'activo' => true,
        ]);

        // Precio en la empresa 2
        PrecioLavanderia::create([
            'empresa_id' => $otraEmpresa->id,
            'tipo_prenda_id' => $tipoPrendaOtra->id,
            'servicio_lavanderia_id' => $servicioOtra->id,
            'precio' => 50.00,
        ]);

        // La empresa 1 no tiene precio en la matriz → debe usar precio_base
        $resultado = PrecioLavanderia::precioParaCombinacion(
            $this->empresa->id,
            $this->tipoPrenda->id,
            $this->servicioConItbis->id,
        );

        $this->assertNull($resultado);
    }

    public function test_precio_lavanderia_modelo_tiene_relaciones(): void
    {
        $precio = PrecioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'tipo_prenda_id' => $this->tipoPrenda->id,
            'servicio_lavanderia_id' => $this->servicioConItbis->id,
            'precio' => 120.00,
        ]);

        $this->assertEquals($this->empresa->id, $precio->empresa->id);
        $this->assertEquals($this->tipoPrenda->id, $precio->tipoPrenda->id);
        $this->assertEquals($this->servicioConItbis->id, $precio->servicioLavanderia->id);
    }

    public function test_precio_lavanderia_unique_constraint(): void
    {
        PrecioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'tipo_prenda_id' => $this->tipoPrenda->id,
            'servicio_lavanderia_id' => $this->servicioConItbis->id,
            'precio' => 120.00,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PrecioLavanderia::create([
            'empresa_id' => $this->empresa->id,
            'tipo_prenda_id' => $this->tipoPrenda->id,
            'servicio_lavanderia_id' => $this->servicioConItbis->id,
            'precio' => 200.00,
        ]);
    }

    public function test_precio_lavanderia_policy_no_permite_delete(): void
    {
        $policy = new \App\Policies\PrecioLavanderiaPolicy();
        $this->assertFalse($policy->delete($this->admin, new PrecioLavanderia()));
    }

    // ───────────────────────────────────────────────────────────────────
    //  Validación de monto recibido
    // ───────────────────────────────────────────────────────────────────

    public function test_cobrar_efectivo_sin_monto_lanza_excepcion(): void
    {
        $ticket = $this->crearTicket();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Debe ingresar el monto recibido');

        app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 0,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_cobrar_efectivo_monto_menor_al_total_lanza_excepcion(): void
    {
        $ticket = $this->crearTicket();
        // total = 354.00

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no puede ser menor al total');

        app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 100.00,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_cobrar_tarjeta_no_requiere_monto_efectivo(): void
    {
        $ticket = $this->crearTicket();

        $venta = app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::TARJETA,
            'user_id' => $this->admin->id,
        ]);

        $ticket->refresh();
        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);
        $this->assertNotNull($venta->id);
    }

    public function test_crear_con_pagar_ahora_efectivo_sin_monto_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Debe ingresar el monto recibido');

        $this->crearTicket([
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
                'monto_efectivo' => 0,
                'user_id' => $this->admin->id,
            ],
        ]);
    }

    public function test_crear_con_pagar_ahora_efectivo_monto_menor_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no puede ser menor al total');

        $this->crearTicket([
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
                'monto_efectivo' => 50.00,
                'user_id' => $this->admin->id,
            ],
        ]);
    }

    // ───────────────────────────────────────────────────────────────────
    //  Flujo completo end-to-end
    // ───────────────────────────────────────────────────────────────────

    public function test_flujo_crear_listo_cobrar_entregar(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        // Estado inicial
        $this->assertEquals(EstadoTicketLavanderia::RECIBIDO, $ticket->estado);
        $this->assertEquals(EstadoPagoTicket::PENDIENTE, $ticket->estado_pago);

        // RECIBIDO → LISTO
        $service->avanzarEstado($ticket);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::LISTO, $ticket->estado);
        $this->assertEquals(EstadoPagoTicket::PENDIENTE, $ticket->estado_pago);

        // Cobrar (ticket en LISTO, pago pendiente)
        $venta = $service->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 400.00,
            'user_id' => $this->admin->id,
        ]);
        $ticket->refresh();
        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);
        $this->assertEquals(EstadoTicketLavanderia::LISTO, $ticket->estado); // No cambia estado
        $this->assertEquals($venta->id, $ticket->venta_id);

        // Entregar (ya pagado, no auto-factura)
        $service->entregar($ticket, $this->empresa);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::ENTREGADO, $ticket->estado);
        $this->assertNotNull($ticket->entregado_en);
    }

    public function test_flujo_crear_con_pago_listo_entregar(): void
    {
        $service = app(TicketLavanderiaService::class);

        // Crear ticket pagando al recibirlo
        $ticket = $this->crearTicket([
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
                'monto_efectivo' => 400.00,
                'user_id' => $this->admin->id,
            ],
        ]);

        $this->assertEquals(EstadoTicketLavanderia::RECIBIDO, $ticket->estado);
        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);
        $this->assertNotNull($ticket->venta_id);

        // RECIBIDO → LISTO
        $service->avanzarEstado($ticket);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::LISTO, $ticket->estado);

        // Entregar (ya pagado, sin facturación adicional)
        $ventaIdAntes = $ticket->venta_id;
        $service->entregar($ticket, $this->empresa);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::ENTREGADO, $ticket->estado);
        $this->assertEquals($ventaIdAntes, $ticket->venta_id); // No genera nueva venta
    }

    public function test_no_se_puede_entregar_sin_cobrar(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        // RECIBIDO → LISTO
        $service->avanzarEstado($ticket);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Debe cobrar el ticket antes de entregarlo');

        $service->entregar($ticket, $this->empresa);
    }

    public function test_flujo_cancelar_ticket_recibido(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        $service->cancelar($ticket);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::CANCELADO, $ticket->estado);
        $this->assertEquals(EstadoPagoTicket::PENDIENTE, $ticket->estado_pago);
    }

    public function test_flujo_cancelar_ticket_listo(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        $service->avanzarEstado($ticket);
        $ticket->refresh();

        $service->cancelar($ticket);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::CANCELADO, $ticket->estado);
    }

    public function test_flujo_cancelar_ticket_pagado_no_entregado(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        // Cobrar (en estado RECIBIDO)
        $service->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::TARJETA,
            'user_id' => $this->admin->id,
        ]);
        $ticket->refresh();
        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);

        // Cancelar (pagado pero no entregado → permitido)
        $service->cancelar($ticket);
        $ticket->refresh();
        $this->assertEquals(EstadoTicketLavanderia::CANCELADO, $ticket->estado);
    }

    public function test_flujo_no_se_puede_cancelar_ticket_entregado(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        $service->avanzarEstado($ticket);
        $ticket->refresh();

        // Cobrar antes de entregar (ahora es obligatorio)
        $service->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::TARJETA,
            'user_id' => $this->admin->id,
        ]);
        $ticket->refresh();

        $service->entregar($ticket, $this->empresa);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya entregado');

        $service->cancelar($ticket);
    }

    public function test_flujo_no_se_puede_cobrar_despues_de_cancelar(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        $service->cancelar($ticket);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ticket cancelado');

        $service->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 354.00,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_flujo_no_se_puede_avanzar_despues_de_cancelar(): void
    {
        $service = app(TicketLavanderiaService::class);
        $ticket = $this->crearTicket();

        $service->cancelar($ticket);
        $ticket->refresh();

        $this->expectException(RuntimeException::class);

        $service->avanzarEstado($ticket);
    }

    public function test_cobrar_con_monto_exacto_funciona(): void
    {
        $ticket = $this->crearTicket();
        // total = 354.00

        $venta = app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 354.00,
            'user_id' => $this->admin->id,
        ]);

        $ticket->refresh();
        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);
    }

    public function test_cobrar_con_vuelto_funciona(): void
    {
        $ticket = $this->crearTicket();
        // total = 354.00

        $venta = app(TicketLavanderiaService::class)->cobrar($ticket, $this->empresa, [
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'monto_efectivo' => 500.00,
            'vuelto' => 146.00,
            'user_id' => $this->admin->id,
        ]);

        $ticket->refresh();
        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);
    }

    public function test_crear_con_pagar_ahora_tarjeta_sin_monto_funciona(): void
    {
        $ticket = $this->crearTicket([
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => \App\Enums\FormaPago::TARJETA,
                'user_id' => $this->admin->id,
            ],
        ]);

        $this->assertEquals(EstadoPagoTicket::PAGADO, $ticket->estado_pago);
        $this->assertNotNull($ticket->venta_id);
    }
}
