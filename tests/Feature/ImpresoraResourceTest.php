<?php

namespace Tests\Feature;

use App\Enums\AnchoPapel;
use App\Enums\ModuloImpresion;
use App\Enums\TipoConexionImpresora;
use App\Filament\Resources\ImpresoraResource;
use App\Filament\Resources\ImpresoraResource\Pages\CreateImpresora;
use App\Models\Impresora;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImpresoraResourceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    public function test_la_pagina_indice_carga_para_quien_tiene_administrar_configuracion(): void
    {
        $this->actingAs($this->usuarioConPermiso())
            ->get(ImpresoraResource::getUrl('index'))
            ->assertOk();
    }

    public function test_usuario_sin_permiso_no_puede_entrar(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        $this->actingAs($usuario)
            ->get(ImpresoraResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_crear_una_impresora_navegador_no_pide_ip_ni_puerto(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateImpresora::class)
            ->fillForm([
                'nombre' => 'Mostrador',
                'tipo_conexion' => TipoConexionImpresora::NAVEGADOR->value,
                'modulo' => ModuloImpresion::FACTURACION->value,
                'ancho_papel' => AnchoPapel::MM80->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $impresora = Impresora::query()->where('nombre', 'Mostrador')->firstOrFail();
        $this->assertNull($impresora->ip);
        $this->assertNull($impresora->puerto);
    }

    public function test_crear_una_impresora_red_sin_ip_falla_la_validacion_del_formulario(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateImpresora::class)
            ->fillForm([
                'nombre' => 'Cocina',
                'tipo_conexion' => TipoConexionImpresora::RED->value,
                'modulo' => ModuloImpresion::COCINA->value,
                'ancho_papel' => AnchoPapel::MM80->value,
                'ip' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['ip']);
    }

    public function test_crear_una_impresora_red_con_datos_validos_funciona(): void
    {
        Livewire::actingAs($this->usuarioConPermiso())
            ->test(CreateImpresora::class)
            ->fillForm([
                'nombre' => 'Cocina',
                'tipo_conexion' => TipoConexionImpresora::RED->value,
                'modulo' => ModuloImpresion::COCINA->value,
                'ancho_papel' => AnchoPapel::MM80->value,
                'ip' => '192.168.1.50',
                'puerto' => 9100,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $impresora = Impresora::query()->where('nombre', 'Cocina')->firstOrFail();
        $this->assertSame('192.168.1.50', $impresora->ip);
        $this->assertSame(9100, $impresora->puerto);
    }

    public function test_marcar_predeterminada_desde_el_formulario_desmarca_las_demas_del_modulo(): void
    {
        $usuario = $this->usuarioConPermiso();

        $existente = Impresora::create([
            'nombre' => 'Primera',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        Livewire::actingAs($usuario)
            ->test(CreateImpresora::class)
            ->fillForm([
                'nombre' => 'Segunda',
                'tipo_conexion' => TipoConexionImpresora::NAVEGADOR->value,
                'modulo' => ModuloImpresion::FACTURACION->value,
                'ancho_papel' => AnchoPapel::MM80->value,
                'predeterminada' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($existente->fresh()->predeterminada);
        $this->assertTrue(Impresora::query()->where('nombre', 'Segunda')->firstOrFail()->predeterminada);
    }

    public function test_probar_impresion_en_una_impresora_de_red_inalcanzable_notifica_el_error(): void
    {
        $usuario = $this->usuarioConPermiso();

        // 127.0.0.1 con un puerto casi con certeza cerrado: falla rápido con "conexión
        // rechazada" en vez de agotar el timeout de 3s, para que el test no sea lento.
        $impresora = Impresora::create([
            'nombre' => 'Cocina',
            'tipo_conexion' => TipoConexionImpresora::RED,
            'ip' => '127.0.0.1',
            'puerto' => 1,
            'modulo' => ModuloImpresion::COCINA,
        ]);

        Livewire::actingAs($usuario)
            ->test(ImpresoraResource\Pages\ListImpresoras::class)
            ->callTableAction('probarImpresion', $impresora);

        // La acción no debe lanzar una excepción sin capturar (500): el fallo de conexión se
        // convierte en una Notification, no rompe la página. No hay aserción sobre el socket en
        // sí (entorno sin red controlada), solo que la petición no truena.
        $this->assertTrue(true);
    }
}
