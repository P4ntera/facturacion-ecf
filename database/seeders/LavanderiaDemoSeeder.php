<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FormaPago;
use App\Enums\PerfilEmpresa;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\PrecioLavanderia;
use App\Models\ServicioLavanderia;
use App\Models\TicketLavanderia;
use App\Models\TipoPrenda;
use App\Models\User;
use App\Services\TicketLavanderiaService;
use App\Support\ComprobanteVenta;
use Database\Seeders\Concerns\SiembraEmpresaDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Lavandería demo en tres escenarios fiscales (ver SiembraEmpresaDemo):
 *
 *  - Lavandería Doña Rosa  (informal) — sin RNC, sin ITBIS, solo sin comprobante.
 *  - Lavandería Express    (tipo B)   — ITBIS incluido en precio, B01/B02 + sin comprobante.
 *  - Lavandería Premium    (e-CF)     — ITBIS aparte, E31/E32, toda venta lleva e-NCF.
 *
 * Cada una con catálogo (6 prendas, 5 servicios — uno sin ITBIS —, matriz de precios), tres
 * usuarios (admin, recepcionista, operador), tres clientes (RNC, cédula, sin documento) y
 * tickets que recorren todos los estados y formas de pago, con el comprobante que corresponde
 * a cada escenario.
 *
 *   sail artisan db:seed --class=LavanderiaDemoSeeder
 */
class LavanderiaDemoSeeder extends Seeder
{
    use SiembraEmpresaDemo;

    public function run(): void
    {
        $this->abortarEnProduccion();

        $this->command->info('Lavandería demo:');

        $this->sembrarEscenario(self::ESCENARIO_INFORMAL, [
            'razon_social' => 'Lavandería Doña Rosa',
            'nombre_comercial' => 'Lavandería Doña Rosa',
            'rnc' => null,
            'slug' => 'demo-lavanderia-informal',
            'email_admin' => 'admin@lav-informal.test',
        ], 'lav-informal', '0000101');

        $this->sembrarEscenario(self::ESCENARIO_TIPO_B, [
            'razon_social' => 'Lavandería Express RD SRL',
            'nombre_comercial' => 'Lavandería Express',
            'rnc' => '131900101',
            'slug' => 'demo-lavanderia-tipo-b',
            'email_admin' => 'admin@lav-tipob.test',
        ], 'lav-tipob', '0000102');

        $this->sembrarEscenario(self::ESCENARIO_ECF, [
            'razon_social' => 'Lavandería Premium Dry Clean SRL',
            'nombre_comercial' => 'Lavandería Premium',
            'rnc' => '131900102',
            'slug' => 'demo-lavanderia-ecf',
            'email_admin' => 'admin@lav-ecf.test',
        ], 'lav-ecf', '0000103');
    }

    /** @param  array<string, ?string>  $datos */
    private function sembrarEscenario(string $escenario, array $datos, string $dominio, string $sufijoRnc): void
    {
        [$empresa, $admin] = $this->crearEmpresaDemo(PerfilEmpresa::LAVANDERIA, $escenario, $datos);

        $recepcionista = $this->crearUsuarioDemo($empresa, 'María Recepción', "recepcion@{$dominio}.test", 'Recepcionista Lavandería');
        $this->crearUsuarioDemo($empresa, 'Juan Operador', "operador@{$dominio}.test", 'Operador Lavandería');

        $prendas = $this->sembrarPrendas($empresa);
        $servicios = $this->sembrarServicios($empresa);
        $this->sembrarMatriz($empresa, $prendas, $servicios);
        $clientes = $this->sembrarClientesDemo($empresa, $sufijoRnc);

        if (! TicketLavanderia::where('empresa_id', $empresa->id)->exists()) {
            $this->sembrarTickets($empresa, $escenario, $recepcionista, $prendas, $servicios, $clientes);
        }

        $this->resumenEmpresa($empresa, $escenario, [
            $admin->email, "recepcion@{$dominio}.test", "operador@{$dominio}.test",
        ]);
    }

    /** @return Collection<string, TipoPrenda> */
    private function sembrarPrendas(Empresa $empresa): Collection
    {
        return collect(['Camisa', 'Pantalón', 'Vestido', 'Sábana', 'Traje completo', 'Cortina'])
            ->mapWithKeys(fn (string $nombre) => [$nombre => TipoPrenda::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                ['activo' => true],
            )]);
    }

    /** @return Collection<string, ServicioLavanderia> */
    private function sembrarServicios(Empresa $empresa): Collection
    {
        $datos = [
            ['Lavado regular', 150.00, true],
            ['Lavado y planchado', 250.00, true],
            ['Planchado solo', 100.00, true],
            ['Lavado en seco', 400.00, true],
            // Servicio sin ITBIS: en las empresas que sí lo cobran, prueba la factura mixta
            // (líneas gravadas + líneas sin ITBIS en el mismo comprobante).
            ['Remiendo / costura', 75.00, false],
        ];

        return collect($datos)->mapWithKeys(fn (array $d) => [$d[0] => ServicioLavanderia::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => $d[0]],
            ['precio_base' => $d[1], 'aplica_itbis' => $d[2], 'activo' => true],
        )]);
    }

    /**
     * Matriz prenda × servicio: los pares que no están aquí usan el precio base del servicio,
     * así se prueban los tres niveles de precio (manual > matriz > base).
     */
    private function sembrarMatriz(Empresa $empresa, Collection $prendas, Collection $servicios): void
    {
        $matriz = [
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

        foreach ($matriz as [$prenda, $servicio, $precio]) {
            PrecioLavanderia::firstOrCreate(
                [
                    'empresa_id' => $empresa->id,
                    'tipo_prenda_id' => $prendas[$prenda]->id,
                    'servicio_lavanderia_id' => $servicios[$servicio]->id,
                ],
                ['precio' => $precio],
            );
        }
    }

    /**
     * Tickets que cubren: con/sin cliente, pago inmediato/diferido, efectivo con vuelto, tarjeta,
     * transferencia, precio manual, línea sin ITBIS, todos los estados (recibido, listo,
     * entregado, cancelado) y el comprobante de cada escenario (sin comprobante, B02, B01, E32,
     * E31 con RNC).
     *
     * @param  array{rnc: Cliente, cedula: Cliente, sin_documento: Cliente}  $clientes
     */
    private function sembrarTickets(Empresa $empresa, string $escenario, User $recepcionista, Collection $prendas, Collection $servicios, array $clientes): void
    {
        $servicio = app(TicketLavanderiaService::class);
        $manana = now()->addDay()->toDateTimeString();
        $linea = fn (string $prenda, string $serv, int $cantidad, ?float $precio = null) => array_filter([
            'tipo_prenda_id' => $prendas[$prenda]->id,
            'servicio_lavanderia_id' => $servicios[$serv]->id,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
        ], fn ($v) => $v !== null);

        // Comprobantes del escenario: consumo y crédito fiscal (null = el que diga la empresa).
        [$consumo, $credito] = match ($escenario) {
            self::ESCENARIO_INFORMAL => [ComprobanteVenta::SIN_COMPROBANTE, null],
            self::ESCENARIO_TIPO_B => ['B02', 'B01'],
            self::ESCENARIO_ECF => ['32', '31'],
        };

        $crear = fn (array $datos) => $servicio->crear($empresa, $recepcionista, $datos + ['fecha_prometida' => $manana]);

        // 1. Cobro rápido al portador, efectivo con vuelto (sin comprobante si la empresa lo permite).
        $crear([
            'pagar_ahora' => true,
            'datos_venta' => ['forma_pago' => FormaPago::EFECTIVO, 'monto_efectivo' => 1000],
            'detalles' => [$linea('Camisa', 'Lavado regular', 2)],
        ]);

        // 2. Consumo con cliente con cédula, tarjeta, varias prendas.
        $crear([
            'cliente_id' => $clientes['cedula']->id,
            'pagar_ahora' => true,
            'datos_venta' => ['forma_pago' => FormaPago::TARJETA, 'comprobante' => $consumo],
            'detalles' => [
                $linea('Pantalón', 'Lavado y planchado', 2),
                $linea('Vestido', 'Lavado en seco', 1),
            ],
        ]);

        // 3. Factura mixta: servicio con ITBIS + servicio sin ITBIS, transferencia.
        $crear([
            'cliente_id' => $clientes['sin_documento']->id,
            'observaciones' => 'Coser botón y lavar',
            'pagar_ahora' => true,
            'datos_venta' => ['forma_pago' => FormaPago::TRANSFERENCIA, 'comprobante' => $consumo],
            'detalles' => [
                $linea('Camisa', 'Lavado y planchado', 1),
                $linea('Camisa', 'Remiendo / costura', 1),
            ],
        ]);

        // 4. Crédito fiscal a cliente con RNC (solo donde hay secuencia de crédito fiscal).
        if ($credito !== null) {
            $crear([
                'cliente_id' => $clientes['rnc']->id,
                'observaciones' => 'Uniformes de la empresa',
                'pagar_ahora' => true,
                'datos_venta' => ['forma_pago' => FormaPago::TRANSFERENCIA, 'comprobante' => $credito],
                'detalles' => [
                    $linea('Camisa', 'Lavado y planchado', 10, 150.00), // precio manual por volumen
                    $linea('Pantalón', 'Lavado y planchado', 10, 180.00),
                ],
            ]);
        }

        // 5. Pago diferido: recibido, sin cobrar.
        $crear([
            'cliente_id' => $clientes['cedula']->id,
            'observaciones' => 'Cortinas del salón',
            'detalles' => [
                $linea('Cortina', 'Lavado regular', 3),
                $linea('Cortina', 'Lavado en seco', 1),
            ],
        ]);

        // 6. Listo, pendiente de cobro (para probar el cobro al retirar con selector de comprobante).
        $t6 = $crear([
            'cliente_id' => $clientes['rnc']->id,
            'detalles' => [$linea('Traje completo', 'Lavado en seco', 2)],
        ]);
        $servicio->avanzarEstado($t6);

        // 7. Flujo completo: listo → cobrado → entregado.
        $t7 = $crear([
            'cliente_id' => $clientes['cedula']->id,
            'detalles' => [
                $linea('Pantalón', 'Lavado regular', 2),
                $linea('Camisa', 'Planchado solo', 1),
            ],
        ]);
        $servicio->avanzarEstado($t7);
        $servicio->cobrar($t7, $empresa, ['forma_pago' => FormaPago::EFECTIVO, 'monto_efectivo' => 1000, 'comprobante' => $consumo]);
        $servicio->entregar($t7->refresh(), $empresa);

        // 8. Cobrado por adelantado, listo, esperando retiro.
        $t8 = $crear([
            'pagar_ahora' => true,
            'datos_venta' => ['forma_pago' => FormaPago::TARJETA, 'comprobante' => $consumo],
            'detalles' => [$linea('Sábana', 'Lavado regular', 4)],
        ]);
        $servicio->avanzarEstado($t8);

        // 9. Cancelado sin cobrar.
        $t9 = $crear([
            'observaciones' => 'Cliente cambió de opinión',
            'detalles' => [$linea('Sábana', 'Lavado regular', 2)],
        ]);
        $servicio->cancelar($t9);
    }
}
