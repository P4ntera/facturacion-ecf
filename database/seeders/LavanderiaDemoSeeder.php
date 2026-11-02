<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Enums\FormaPago;
use App\Enums\PerfilEmpresa;
use App\Enums\TipoDocumentoCliente;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\PrecioLavanderia;
use App\Models\ServicioLavanderia;
use App\Models\TipoPrenda;
use App\Models\User;
use App\Services\ModulosEmpresaService;
use App\Services\RolesEmpresaService;
use App\Services\TicketLavanderiaService;
use Illuminate\Database\Seeder;

/**
 * Seeder demo para el módulo de lavandería:
 * - Crea una empresa con perfil LAVANDERIA (activa roles Recepcionista/Operador)
 * - Siembra catálogos: 6 tipos de prenda, 4 servicios, 12 precios en matriz
 * - Crea 12 tickets con variaciones: con/sin cliente, pagado/pendiente,
 *   distintos estados, múltiples prendas, distintas formas de pago
 */
class LavanderiaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(TicketLavanderiaService::class);

        // ── Empresa con perfil lavandería ────────────────────────────
        $empresa = Empresa::firstOrCreate(
            ['rnc' => '131999001'],
            [
                'razon_social' => 'Lavandería Express Demo SRL',
                'perfil' => PerfilEmpresa::LAVANDERIA,
                'usa_ecf' => false,
                'activa' => true,
            ],
        );

        // Forzar perfil si la empresa ya existía con otro perfil
        if ($empresa->perfil !== PerfilEmpresa::LAVANDERIA) {
            $empresa->update(['perfil' => PerfilEmpresa::LAVANDERIA]);
        }

        // Habilitar ventas sin comprobante (config vive en empresa_configuracion)
        $empresa->config()->update(['permite_ventas_sin_comprobante' => true]);

        // Roles base + roles de lavandería (Recepcionista + Operador)
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);
        app(ModulosEmpresaService::class)->sembrarModulos($empresa);

        // ── Admin ────────────────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'admin@lavanderia-demo.test'],
            [
                'empresa_id' => $empresa->id,
                'name' => 'Admin Lavandería',
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ],
        );
        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresa);

        // ── Recepcionista (usuario con rol específico de lavandería) ─
        $recepcionista = User::firstOrCreate(
            ['email' => 'recepcion@lavanderia-demo.test'],
            [
                'empresa_id' => $empresa->id,
                'name' => 'María Recepcionista',
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ],
        );

        setPermissionsTeamId($empresa->id);
        if (! $recepcionista->hasRole('Recepcionista Lavandería')) {
            $recepcionista->assignRole('Recepcionista Lavandería');
        }

        // ── Operador (usuario con rol específico de lavandería) ──────
        $operador = User::firstOrCreate(
            ['email' => 'operador@lavanderia-demo.test'],
            [
                'empresa_id' => $empresa->id,
                'name' => 'Juan Operador',
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ],
        );

        if (! $operador->hasRole('Operador Lavandería')) {
            $operador->assignRole('Operador Lavandería');
        }

        // ── Catálogo: Tipos de prenda ────────────────────────────────
        $prendas = collect([
            'Camisa',
            'Pantalón',
            'Vestido',
            'Sábana',
            'Traje completo',
            'Cortina',
        ])->mapWithKeys(fn (string $nombre) => [
            $nombre => TipoPrenda::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                ['activo' => true],
            ),
        ]);

        // ── Catálogo: Servicios ──────────────────────────────────────
        $serviciosDatos = [
            ['nombre' => 'Lavado regular', 'precio_base' => 150.00, 'aplica_itbis' => true],
            ['nombre' => 'Lavado y planchado', 'precio_base' => 250.00, 'aplica_itbis' => true],
            ['nombre' => 'Planchado solo', 'precio_base' => 100.00, 'aplica_itbis' => true],
            ['nombre' => 'Lavado en seco', 'precio_base' => 400.00, 'aplica_itbis' => true],
        ];

        $servicios = collect();
        foreach ($serviciosDatos as $dato) {
            $servicios[$dato['nombre']] = ServicioLavanderia::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $dato['nombre']],
                ['precio_base' => $dato['precio_base'], 'aplica_itbis' => $dato['aplica_itbis'], 'activo' => true],
            );
        }

        // ── Matriz de precios (prenda × servicio) ────────────────────
        $matrizPrecios = [
            // [tipo_prenda, servicio, precio_especial]
            ['Camisa', 'Lavado regular', 120.00],
            ['Camisa', 'Lavado y planchado', 200.00],
            ['Camisa', 'Planchado solo', 80.00],
            ['Pantalón', 'Lavado regular', 130.00],
            ['Pantalón', 'Lavado y planchado', 220.00],
            ['Vestido', 'Lavado en seco', 500.00],
            ['Vestido', 'Lavado y planchado', 350.00],
            ['Sábana', 'Lavado regular', 200.00],
            ['Traje completo', 'Lavado en seco', 800.00],
            ['Traje completo', 'Lavado y planchado', 600.00],
            ['Cortina', 'Lavado regular', 300.00],
            ['Cortina', 'Lavado en seco', 550.00],
        ];

        foreach ($matrizPrecios as [$prenda, $servicio, $precio]) {
            PrecioLavanderia::firstOrCreate(
                [
                    'empresa_id' => $empresa->id,
                    'tipo_prenda_id' => $prendas[$prenda]->id,
                    'servicio_lavanderia_id' => $servicios[$servicio]->id,
                ],
                ['precio' => $precio],
            );
        }

        // ── Clientes ────────────────────────────────────────────────
        $clienteRegular = Cliente::firstOrCreate(
            ['empresa_id' => $empresa->id, 'documento' => '00100500001'],
            [
                'tipo_documento' => TipoDocumentoCliente::CEDULA,
                'nombre' => 'Ana García (Cliente Regular)',
                'telefono' => '809-555-0001',
                'activo' => true,
            ],
        );

        $clienteVip = Cliente::firstOrCreate(
            ['empresa_id' => $empresa->id, 'documento' => '00100500002'],
            [
                'tipo_documento' => TipoDocumentoCliente::CEDULA,
                'nombre' => 'Carlos Méndez (VIP)',
                'telefono' => '809-555-0002',
                'activo' => true,
            ],
        );

        $clienteOcasional = Cliente::firstOrCreate(
            ['empresa_id' => $empresa->id, 'documento' => '00100500003'],
            [
                'tipo_documento' => TipoDocumentoCliente::CEDULA,
                'nombre' => 'Laura Pérez (Ocasional)',
                'telefono' => '809-555-0003',
                'activo' => true,
            ],
        );

        // ── 12 Tickets de prueba ─────────────────────────────────────
        $this->command->info('Creando tickets de lavandería...');

        $fechaFutura = now()->addHours(24)->toDateTimeString();

        // TICKET 1: Simple — 1 prenda, pago inmediato efectivo, con cliente
        $t1 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteRegular->id,
            'observaciones' => 'Mancha de café en el frente',
            'fecha_prometida' => $fechaFutura,
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => FormaPago::EFECTIVO,
                'monto_efectivo' => 500.00,
            ],
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Lavado regular']->id, 'cantidad' => 1],
            ],
        ]);
        $this->command->info("  TK1: {$t1->numero} — 1 camisa lavado regular, pagado efectivo (RD$ {$t1->total})");

        // TICKET 2: Múltiples prendas misma persona, pago diferido (pendiente)
        $t2 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteVip->id,
            'observaciones' => 'Ropa para evento del sábado — prioridad',
            'fecha_prometida' => $fechaFutura,
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Traje completo']->id, 'servicio_lavanderia_id' => $servicios['Lavado en seco']->id, 'cantidad' => 1],
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 3],
                ['tipo_prenda_id' => $prendas['Pantalón']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 2],
            ],
        ]);
        $this->command->info("  TK2: {$t2->numero} — traje + 3 camisas + 2 pantalones, pago pendiente (RD$ {$t2->total})");

        // TICKET 3: Al portador (sin cliente), pago inmediato tarjeta
        $t3 = $service->crear($empresa, $recepcionista, [
            'observaciones' => null,
            'fecha_prometida' => $fechaFutura,
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => FormaPago::TARJETA,
            ],
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Sábana']->id, 'servicio_lavanderia_id' => $servicios['Lavado regular']->id, 'cantidad' => 4],
            ],
        ]);
        $this->command->info("  TK3: {$t3->numero} — 4 sábanas al portador, pagado tarjeta (RD$ {$t3->total})");

        // TICKET 4: Solo planchado, precio de matriz
        $t4 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteOcasional->id,
            'fecha_prometida' => $fechaFutura,
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Planchado solo']->id, 'cantidad' => 5],
            ],
        ]);
        $this->command->info("  TK4: {$t4->numero} — 5 camisas planchado solo, pendiente (RD$ {$t4->total})");

        // TICKET 5: Vestidos lavado en seco (precio alto), pago transferencia
        $t5 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteVip->id,
            'observaciones' => 'Vestidos de fiesta — cuidado especial',
            'fecha_prometida' => $fechaFutura,
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => FormaPago::TRANSFERENCIA,
            ],
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Vestido']->id, 'servicio_lavanderia_id' => $servicios['Lavado en seco']->id, 'cantidad' => 2],
            ],
        ]);
        $this->command->info("  TK5: {$t5->numero} — 2 vestidos lavado en seco, pagado transferencia (RD$ {$t5->total})");

        // TICKET 6: Cortinas grandes, pago pendiente
        $t6 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteRegular->id,
            'observaciones' => 'Cortinas del salón — las recogemos el viernes',
            'fecha_prometida' => now()->addHours(72)->toDateTimeString(),
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Cortina']->id, 'servicio_lavanderia_id' => $servicios['Lavado regular']->id, 'cantidad' => 3],
                ['tipo_prenda_id' => $prendas['Cortina']->id, 'servicio_lavanderia_id' => $servicios['Lavado en seco']->id, 'cantidad' => 1],
            ],
        ]);
        $this->command->info("  TK6: {$t6->numero} — 3 cortinas lavado + 1 seco, pendiente (RD$ {$t6->total})");

        // TICKET 7: Precio manual (override de la matriz)
        $t7 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteVip->id,
            'observaciones' => 'Precio especial por volumen',
            'fecha_prometida' => $fechaFutura,
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 10, 'precio_unitario' => 150.00],
                ['tipo_prenda_id' => $prendas['Pantalón']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 10, 'precio_unitario' => 180.00],
            ],
        ]);
        $this->command->info("  TK7: {$t7->numero} — 20 prendas precio manual por volumen, pendiente (RD$ {$t7->total})");

        // TICKET 8: Al portador, mix de servicios, pagado efectivo
        $t8 = $service->crear($empresa, $recepcionista, [
            'fecha_prometida' => $fechaFutura,
            'pagar_ahora' => true,
            'datos_venta' => [
                'forma_pago' => FormaPago::EFECTIVO,
                'monto_efectivo' => 2000.00,
            ],
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Lavado regular']->id, 'cantidad' => 2],
                ['tipo_prenda_id' => $prendas['Pantalón']->id, 'servicio_lavanderia_id' => $servicios['Planchado solo']->id, 'cantidad' => 3],
                ['tipo_prenda_id' => $prendas['Vestido']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 1],
            ],
        ]);
        $this->command->info("  TK8: {$t8->numero} — mix 3 prendas al portador, pagado efectivo (RD$ {$t8->total})");

        // TICKET 9: Para avanzar a LISTO
        $t9 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteRegular->id,
            'fecha_prometida' => $fechaFutura,
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 2],
            ],
        ]);
        // Avanzar a LISTO
        $service->avanzarEstado($t9);
        $t9->refresh();
        $this->command->info("  TK9: {$t9->numero} — avanzado a LISTO, pago pendiente (RD$ {$t9->total})");

        // TICKET 10: Flujo completo → LISTO → cobrar → ENTREGADO
        $t10 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteOcasional->id,
            'observaciones' => 'Entrega urgente',
            'fecha_prometida' => $fechaFutura,
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Pantalón']->id, 'servicio_lavanderia_id' => $servicios['Lavado regular']->id, 'cantidad' => 2],
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Planchado solo']->id, 'cantidad' => 1],
            ],
        ]);
        $service->avanzarEstado($t10);
        $service->cobrar($t10, $empresa, [
            'forma_pago' => FormaPago::EFECTIVO,
            'monto_efectivo' => 1000.00,
        ]);
        $t10->refresh();
        $service->entregar($t10, $empresa);
        $t10->refresh();
        $this->command->info("  TK10: {$t10->numero} — flujo completo ENTREGADO (RD$ {$t10->total})");

        // TICKET 11: Para cancelar
        $t11 = $service->crear($empresa, $recepcionista, [
            'observaciones' => 'Cliente cambió de opinión',
            'fecha_prometida' => $fechaFutura,
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Sábana']->id, 'servicio_lavanderia_id' => $servicios['Lavado regular']->id, 'cantidad' => 2],
            ],
        ]);
        $service->cancelar($t11);
        $t11->refresh();
        $this->command->info("  TK11: {$t11->numero} — CANCELADO (RD$ {$t11->total})");

        // TICKET 12: Listo + cobrado pero NO entregado (esperando retiro)
        $t12 = $service->crear($empresa, $recepcionista, [
            'cliente_id' => $clienteVip->id,
            'observaciones' => 'Cobrado por adelantado, pendiente de retirar',
            'fecha_prometida' => $fechaFutura,
            'detalles' => [
                ['tipo_prenda_id' => $prendas['Traje completo']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 1],
                ['tipo_prenda_id' => $prendas['Camisa']->id, 'servicio_lavanderia_id' => $servicios['Lavado y planchado']->id, 'cantidad' => 2],
            ],
        ]);
        $service->avanzarEstado($t12);
        $service->cobrar($t12, $empresa, [
            'forma_pago' => FormaPago::TARJETA,
        ]);
        $t12->refresh();
        $this->command->info("  TK12: {$t12->numero} — LISTO + cobrado, esperando retiro (RD$ {$t12->total})");

        // ── Resumen ──────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->info('╔══════════════════════════════════════════════════════╗');
        $this->command->info('║  Lavandería Demo sembrada exitosamente              ║');
        $this->command->info('╠══════════════════════════════════════════════════════╣');
        $this->command->info("║  Empresa:    {$empresa->razon_social}");
        $this->command->info("║  RNC:        {$empresa->rnc}");
        $this->command->info('║                                                      ');
        $this->command->info('║  Usuarios:                                           ');
        $this->command->info("║    Admin:        admin@lavanderia-demo.test           ");
        $this->command->info("║    Recepcionista: recepcion@lavanderia-demo.test      ");
        $this->command->info("║    Operador:     operador@lavanderia-demo.test        ");
        $this->command->info('║    (password: password123 para todos)                 ');
        $this->command->info('║                                                      ');
        $this->command->info('║  Roles de lavandería:                                ');
        $this->command->info('║    • Administrador (todos los permisos)               ');
        $this->command->info('║    • Recepcionista Lavandería (tickets + cobros)      ');
        $this->command->info('║    • Operador Lavandería (solo ver + cambiar estado)  ');
        $this->command->info('║    • Vendedor (POS + facturación)                     ');
        $this->command->info('║    • Almacenista (inventario + compras)               ');
        $this->command->info('║                                                      ');
        $this->command->info('║  Catálogos: 6 prendas, 4 servicios, 12 precios       ');
        $this->command->info("║  Tickets:   12 creados                               ");
        $this->command->info('║    • 4 pagados inmediato (efectivo/tarjeta/transf.)   ');
        $this->command->info('║    • 4 pendientes de pago                            ');
        $this->command->info('║    • 1 listo para entrega (pago pendiente)            ');
        $this->command->info('║    • 1 entregado (flujo completo)                    ');
        $this->command->info('║    • 1 cancelado                                     ');
        $this->command->info('║    • 1 listo + cobrado (esperando retiro)             ');
        $this->command->info('╚══════════════════════════════════════════════════════╝');
    }
}