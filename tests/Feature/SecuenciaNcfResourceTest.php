<?php

namespace Tests\Feature;

use App\Enums\TipoComprobante;
use App\Filament\Resources\SecuenciaNcfResource\Pages\CreateSecuenciaNcf;
use App\Models\SecuenciaNcf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecuenciaNcfResourceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'secuencias.administrar', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $rol->syncPermissions(['secuencias.administrar']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    public function test_crea_una_secuencia_y_fija_secuencia_actual_igual_a_desde(): void
    {
        Livewire::actingAs($this->usuarioAutorizado())
            ->test(CreateSecuenciaNcf::class)
            ->fillForm([
                'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
                'prefijo' => 'E32',
                'secuencia_desde' => 10,
                'secuencia_hasta' => 20,
                'vencimiento' => now()->addYear()->toDateString(),
                'activa' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('secuencias_ncf', [
            'prefijo' => 'E32',
            'secuencia_desde' => 10,
            'secuencia_actual' => 10,
            'secuencia_hasta' => 20,
        ]);
    }

    public function test_un_segundo_rango_del_mismo_comprobante_queda_encolado_sin_error(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 100,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(CreateSecuenciaNcf::class)
            ->fillForm([
                'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
                'prefijo' => 'E32',
                'secuencia_desde' => 101,
                'secuencia_hasta' => 200,
                'activa' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('secuencias_ncf', [
            'prefijo' => 'E32',
            'secuencia_desde' => 101,
            'secuencia_hasta' => 200,
            'activa' => false,
        ]);
    }

    public function test_rechaza_un_rango_que_se_solapa_con_uno_existente(): void
    {
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 100,
            'activa' => true,
        ]);

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(CreateSecuenciaNcf::class)
            ->fillForm([
                'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
                'prefijo' => 'E32',
                'secuencia_desde' => 50,
                'secuencia_hasta' => 120,
                'activa' => false,
            ])
            ->call('create')
            ->assertHasFormErrors(['secuencia_hasta']);

        $this->assertDatabaseMissing('secuencias_ncf', ['secuencia_desde' => 50]);
    }
}
