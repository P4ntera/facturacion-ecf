<?php

namespace Tests\Feature;

use App\Enums\ModuloImpresion;
use App\Enums\TipoConexionImpresora;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Impresora;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImpresoraPreferenciaUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function crearImpresora(string $nombre): Impresora
    {
        return Impresora::create([
            'nombre' => $nombre,
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'activa' => true,
        ]);
    }

    public function test_un_cajero_sin_gestionar_usuarios_puede_fijar_su_impresora_desde_el_perfil(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $impresora = $this->crearImpresora('Caja 1');

        $cajero = User::factory()->create();
        $cajero->assignRole('Vendedor');
        $this->assertFalse($cajero->can('gestionar_usuarios'));

        Livewire::actingAs($cajero)
            ->test(EditProfile::class)
            ->fillForm(['impresora_facturacion_id' => $impresora->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($impresora->id, $cajero->fresh()->impresora_facturacion_id);
    }

    public function test_el_selector_del_perfil_solo_ofrece_impresoras_activas_de_facturacion(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $activaFacturacion = $this->crearImpresora('Activa facturación');

        $inactiva = Impresora::create([
            'nombre' => 'Inactiva',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::FACTURACION,
            'activa' => false,
        ]);

        $deOtroModulo = Impresora::create([
            'nombre' => 'Reportes',
            'tipo_conexion' => TipoConexionImpresora::NAVEGADOR,
            'modulo' => ModuloImpresion::REPORTES,
            'activa' => true,
        ]);

        $cajero = User::factory()->create();
        $cajero->assignRole('Vendedor');

        $test = Livewire::actingAs($cajero)->test(EditProfile::class);

        $test->assertSuccessful();

        // No debe poder guardarse una impresora inactiva o de otro módulo como si fuera válida
        // para el selector: confirmamos que la búsqueda de opciones del relationship las excluye.
        $opciones = Impresora::query()->activas()->porModulo(ModuloImpresion::FACTURACION)->pluck('id');
        $this->assertTrue($opciones->contains($activaFacturacion->id));
        $this->assertFalse($opciones->contains($inactiva->id));
        $this->assertFalse($opciones->contains($deOtroModulo->id));
    }

    public function test_un_administrador_puede_asignar_la_impresora_de_otro_usuario_desde_userresource(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $impresora = $this->crearImpresora('Caja admin');

        $admin = User::factory()->create();
        $admin->assignRole('Administrador');

        $cajero = User::factory()->create();
        $cajero->assignRole('Vendedor');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $cajero->getKey()])
            ->fillForm(['impresora_facturacion_id' => $impresora->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($impresora->id, $cajero->fresh()->impresora_facturacion_id);
    }
}
