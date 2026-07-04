<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageEmpresa;
use App\Filament\Pages\ManageFacturacion;
use App\Models\User;
use App\Settings\EmpresaSettings;
use App\Settings\FacturacionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConfiguracionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'administrar_configuracion', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $rol->syncPermissions(['administrar_configuracion']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    public function test_guarda_los_datos_de_la_empresa(): void
    {
        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ManageEmpresa::class)
            ->fillForm([
                'razon_social' => 'Comercial Prueba SRL',
                'nombre_comercial' => 'Prueba',
                'rnc' => '130123456',
                'direccion' => 'Calle Falsa 123',
                'telefono' => '809-555-0000',
                'email' => 'contacto@prueba.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(EmpresaSettings::class);
        $this->assertSame('Comercial Prueba SRL', $settings->razon_social);
        $this->assertSame('130123456', $settings->rnc);
    }

    public function test_rechaza_un_rnc_con_formato_invalido(): void
    {
        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ManageEmpresa::class)
            ->fillForm([
                'razon_social' => 'Comercial Prueba SRL',
                'nombre_comercial' => 'Prueba',
                'rnc' => '123',
            ])
            ->call('save')
            ->assertHasFormErrors(['rnc']);
    }

    public function test_guarda_la_configuracion_de_facturacion(): void
    {
        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ManageFacturacion::class)
            ->fillForm([
                'aplica_itbis' => true,
                'precio_incluye_itbis' => true,
                'tasa_itbis_defecto' => '16',
                'tipo_comprobante_defecto' => '31',
                'moneda' => 'USD',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(FacturacionSettings::class);
        $this->assertTrue($settings->precio_incluye_itbis);
        $this->assertSame('16', $settings->tasa_itbis_defecto);
        $this->assertSame('USD', $settings->moneda);
    }

    public function test_un_usuario_sin_permiso_no_puede_acceder(): void
    {
        $usuario = User::factory()->create();
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $usuario->assignRole($rol);

        $this->actingAs($usuario)
            ->get(ManageFacturacion::getUrl())
            ->assertForbidden();
    }
}
