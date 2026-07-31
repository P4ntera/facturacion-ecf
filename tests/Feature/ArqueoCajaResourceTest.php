<?php

namespace Tests\Feature;

use App\Filament\Resources\ArqueoCajaResource;
use App\Filament\Resources\ArqueoCajaResource\Pages\ListArqueosCaja;
use App\Models\User;
use App\Services\ArqueoCajaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArqueoCajaResourceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    public function test_la_pagina_indice_de_arqueos_carga_para_quien_tiene_permiso(): void
    {
        $usuario = $this->usuarioConPermiso();

        $this->actingAs($usuario)
            ->get(ArqueoCajaResource::getUrl('index'))
            ->assertOk();
    }

    public function test_usuario_sin_permiso_no_puede_entrar_a_arqueos(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(ArqueoCajaResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_la_pagina_de_ver_un_arqueo_carga_sin_errores(): void
    {
        $usuario = $this->usuarioConPermiso();
        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $usuario->id, $this->empresaDefault);

        $this->actingAs($usuario)
            ->get(ArqueoCajaResource::getUrl('view', ['record' => $arqueo]))
            ->assertOk();
    }

    public function test_descarga_pdf_de_arqueo_cerrado(): void
    {
        $usuario = $this->usuarioConPermiso();
        $service = app(ArqueoCajaService::class);
        $arqueo = $service->abrir('500.00', $usuario->id, $this->empresaDefault);
        $arqueo = $service->cerrar($arqueo, '500.00', null, $usuario->id);

        $this->actingAs($usuario)
            ->get(route('arqueos-caja.pdf', $arqueo))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_usuario_sin_permiso_no_puede_descargar_pdf(): void
    {
        $usuario = User::factory()->create();
        $cajero = $this->usuarioConPermiso();
        $service = app(ArqueoCajaService::class);
        $arqueo = $service->abrir('500.00', $cajero->id, $this->empresaDefault);
        $arqueo = $service->cerrar($arqueo, '500.00', null, $cajero->id);

        $this->actingAs($usuario)
            ->get(route('arqueos-caja.pdf', $arqueo))
            ->assertForbidden();
    }

    public function test_cerrar_caja_desde_la_tabla_cierra_el_arqueo_y_calcula_diferencia(): void
    {
        $usuario = $this->usuarioConPermiso();
        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $usuario->id, $this->empresaDefault);

        Livewire::actingAs($usuario)
            ->test(ListArqueosCaja::class)
            ->callTableAction('cerrarCaja', $arqueo, data: [
                'efectivo_contado' => '600.00',
                'notas' => 'sobrante de prueba',
            ])
            ->assertHasNoTableActionErrors();

        $arqueo->refresh();
        $this->assertTrue($arqueo->estaCerrado());
        $this->assertSame('600.00', (string) $arqueo->efectivo_contado);
        $this->assertSame('100.00', (string) $arqueo->diferencia);
    }

    public function test_accion_cerrar_caja_no_visible_para_otro_usuario(): void
    {
        $abrio = $this->usuarioConPermiso();
        $otro = $this->usuarioConPermiso();
        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $abrio->id, $this->empresaDefault);

        Livewire::actingAs($otro)
            ->test(ListArqueosCaja::class)
            ->assertTableActionHidden('cerrarCaja', $arqueo);
    }

    public function test_accion_cerrar_caja_no_visible_si_ya_esta_cerrado(): void
    {
        $usuario = $this->usuarioConPermiso();
        $service = app(ArqueoCajaService::class);
        $arqueo = $service->abrir('500.00', $usuario->id, $this->empresaDefault);
        $arqueo = $service->cerrar($arqueo, '500.00', null, $usuario->id);

        Livewire::actingAs($usuario)
            ->test(ListArqueosCaja::class)
            ->assertTableActionHidden('cerrarCaja', $arqueo);
    }
}
