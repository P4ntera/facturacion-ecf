<?php

namespace Tests\Feature;

use App\Filament\Resources\CompraResource;
use App\Filament\Resources\CompraResource\Pages\CreateCompra;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompraResourceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        return $usuario;
    }

    public function test_la_pagina_indice_de_compras_muestra_directamente_el_formulario_de_crear(): void
    {
        $usuario = $this->usuarioConPermiso();

        $this->actingAs($usuario)
            ->get(CompraResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Datos de la factura de compra');
    }

    public function test_usuario_sin_permiso_no_puede_entrar_a_compras(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(CompraResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_el_formulario_de_crear_compra_carga_sin_errores(): void
    {
        $usuario = $this->usuarioConPermiso();

        Livewire::actingAs($usuario)
            ->test(CreateCompra::class)
            ->assertOk();
    }
}
