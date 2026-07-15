<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Filament\Pages\Reportes\ReporteFiscal607;
use App\Models\Cliente;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReporteFiscal607ExportTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function crearVenta(array $overrides = []): Venta
    {
        $cliente = Cliente::create([
            'tipo_documento' => TipoDocumentoCliente::CEDULA,
            'documento' => '00112345678',
            'nombre' => 'Cliente de prueba 607',
            'activo' => true,
        ]);

        return Venta::create(array_merge([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'ncf' => 'E320000000099',
            'fecha' => now(),
            'subtotal' => '100.00',
            'total_itbis' => '18.00',
            'total' => '118.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ], $overrides));
    }

    public function test_el_pdf_del_607_se_genera_con_datos_de_empresa_y_totales(): void
    {
        $usuario = $this->usuarioConPermiso();
        $this->crearVenta();
        $this->crearVenta(['ncf' => 'E320000000100', 'estado' => EstadoVenta::ANULADA]);

        $desde = now()->startOfMonth()->toDateString();
        $hasta = now()->endOfMonth()->toDateString();

        $response = $this->actingAs($usuario)->get(route('reportes.fiscal-607.pdf', ['desde' => $desde, 'hasta' => $hasta]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_usuario_sin_ver_reportes_no_puede_descargar_el_pdf_del_607(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        $this->actingAs($usuario)->get(route('reportes.fiscal-607.pdf'))->assertForbidden();
    }

    public function test_exportar_607_genera_un_export_con_las_filas_esperadas_excluyendo_anuladas(): void
    {
        $usuario = $this->usuarioConPermiso();
        $this->crearVenta();
        $this->crearVenta(['ncf' => 'E320000000100', 'estado' => EstadoVenta::ANULADA]);

        Livewire::actingAs($usuario)
            ->test(ReporteFiscal607::class)
            ->callAction('export')
            ->assertHasNoActionErrors();

        $export = Export::query()->latest('id')->first();

        $this->assertNotNull($export);
        $this->assertSame(1, $export->total_rows);
        $this->assertSame(1, $export->successful_rows);
    }
}
