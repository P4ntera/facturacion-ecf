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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteFiscal607PageTest extends TestCase
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

    public function test_la_pagina_del_607_carga_y_muestra_la_venta_con_su_ncf(): void
    {
        $usuario = $this->usuarioConPermiso();
        $this->crearVenta();

        $this->actingAs($usuario)
            ->get(ReporteFiscal607::getUrl())
            ->assertOk()
            ->assertSee('E320000000099')
            ->assertSee('00112345678')
            ->assertSee('Cédula');
    }

    public function test_la_venta_anulada_no_aparece_en_la_pagina_del_607(): void
    {
        $usuario = $this->usuarioConPermiso();
        $this->crearVenta(['ncf' => 'E320000000098', 'estado' => EstadoVenta::ANULADA]);
        $this->crearVenta(['ncf' => 'E320000000097']);

        $this->actingAs($usuario)
            ->get(ReporteFiscal607::getUrl())
            ->assertOk()
            ->assertDontSee('E320000000098')
            ->assertSee('E320000000097');
    }

    public function test_usuario_sin_ver_reportes_no_puede_entrar_al_607(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        $this->actingAs($usuario)->get(ReporteFiscal607::getUrl())->assertForbidden();
    }
}
