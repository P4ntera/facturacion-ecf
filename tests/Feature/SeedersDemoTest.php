<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\SaborHelado;
use App\Models\TicketLavanderia;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\HeladeriaSeeder;
use Database\Seeders\LavanderiaDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VeterinariaDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los seeders demo (lavandería, heladería, veterinaria) siembran sus nueve empresas sin error,
 * con totales coherentes, sin mezclar datos entre empresas, y se pueden correr dos veces.
 */
class SeedersDemoTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDERS = [RolePermissionSeeder::class, LavanderiaDemoSeeder::class, HeladeriaSeeder::class, VeterinariaDemoSeeder::class];

    public function test_los_seeders_demo_crean_los_tres_escenarios_y_son_idempotentes(): void
    {
        $this->seed(self::SEEDERS);

        $conteo = fn () => [Empresa::count(), User::count(), Venta::count(), TicketLavanderia::count(), SaborHelado::count()];
        $primera = $conteo();

        // Las del TestCase base + 9 demo.
        $this->assertSame(9, Empresa::where('slug', 'like', 'demo-%')->count());

        $this->seed(self::SEEDERS);
        $this->assertSame($primera, $conteo(), 'Correr los seeders dos veces no debe duplicar nada.');

        // Configuración fiscal de cada escenario.
        foreach (['lavanderia', 'heladeria', 'veterinaria'] as $vertical) {
            $informal = Empresa::where('slug', "demo-{$vertical}-informal")->firstOrFail();
            $tipoB = Empresa::where('slug', "demo-{$vertical}-tipo-b")->firstOrFail();
            $ecf = Empresa::where('slug', "demo-{$vertical}-ecf")->firstOrFail();

            $this->assertFalse($informal->config()->aplica_itbis);
            $this->assertTrue($informal->config()->permite_ventas_sin_comprobante);
            $this->assertTrue($tipoB->config()->precio_incluye_itbis);
            $this->assertTrue($ecf->usaEcf());
            $this->assertFalse($ecf->config()->permite_ventas_sin_comprobante);

            // Toda venta de la empresa e-CF lleva e-NCF de 13 caracteres; la informal ninguna.
            $this->assertSame(0, Venta::where('empresa_id', $ecf->id)->whereNull('ncf')->count());
            $this->assertSame(0, Venta::where('empresa_id', $informal->id)->whereNotNull('ncf')->count());
        }

        // Ticket y factura siempre cuadran.
        TicketLavanderia::whereNotNull('venta_id')->with('venta')->get()->each(
            fn (TicketLavanderia $t) => $this->assertSame((string) $t->total, (string) $t->venta->total, "Ticket {$t->numero}")
        );

        // Los catálogos de heladería solo existen en heladerías.
        $this->assertSame(0, SaborHelado::whereHas('empresa', fn ($q) => $q->where('perfil', '!=', 'heladeria'))->count());

        // Ningún NCF con largo inválido ni cliente de otra empresa.
        $this->assertSame(0, Venta::whereNotNull('ncf')->whereRaw('length(ncf) not in (11, 13)')->count());
        $this->assertSame(0, Venta::join('clientes', 'clientes.id', '=', 'ventas.cliente_id')->whereColumn('clientes.empresa_id', '!=', 'ventas.empresa_id')->count());
    }
}
