<?php

namespace Tests\Feature;

use App\Filament\Resources\ArqueoCajaResource;
use App\Models\User;
use App\Services\ArqueoCajaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $arqueo = app(ArqueoCajaService::class)->abrir('500.00', $usuario->id);

        $this->actingAs($usuario)
            ->get(ArqueoCajaResource::getUrl('view', ['record' => $arqueo]))
            ->assertOk();
    }

    public function test_descarga_pdf_de_arqueo_cerrado(): void
    {
        $usuario = $this->usuarioConPermiso();
        $service = app(ArqueoCajaService::class);
        $arqueo = $service->abrir('500.00', $usuario->id);
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
        $arqueo = $service->abrir('500.00', $cajero->id);
        $arqueo = $service->cerrar($arqueo, '500.00', null, $cajero->id);

        $this->actingAs($usuario)
            ->get(route('arqueos-caja.pdf', $arqueo))
            ->assertForbidden();
    }
}
