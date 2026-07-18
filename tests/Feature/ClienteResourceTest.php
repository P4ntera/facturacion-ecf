<?php

namespace Tests\Feature;

use App\Enums\TipoDocumentoCliente;
use App\Filament\Resources\ClienteResource\Pages\CreateCliente;
use App\Models\User;
use App\Services\Dgii\DgiiGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\GatewayStub;
use Tests\TestCase;

class ClienteResourceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'clientes.ver', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'clientes.crear', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Rol-cliente-dgii', 'guard_name' => 'web']);
        $rol->syncPermissions(['clientes.ver', 'clientes.crear']);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    public function test_buscar_en_dgii_autocompleta_nombre_y_tipo_documento_por_rnc(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarContribuyente(string $valor): ?array
            {
                return ['rnc' => $valor, 'nombre' => 'Comercial Autocompletada SRL'];
            }
        });

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(CreateCliente::class)
            ->fillForm(['documento' => '130123456'])
            ->callFormComponentAction('documento', 'buscarDgii')
            ->assertFormSet([
                'documento' => '130123456',
                'nombre' => 'Comercial Autocompletada SRL',
                'tipo_documento' => TipoDocumentoCliente::RNC->value,
            ]);
    }

    public function test_buscar_en_dgii_autocompleta_por_cedula(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarCedulaJce(string $cedula): ?array
            {
                return ['cedula' => $cedula, 'nombre' => 'Juan Pérez'];
            }
        });

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(CreateCliente::class)
            ->fillForm(['documento' => '00112345678'])
            ->callFormComponentAction('documento', 'buscarDgii')
            ->assertFormSet([
                'nombre' => 'Juan Pérez',
                'tipo_documento' => TipoDocumentoCliente::CEDULA->value,
            ]);
    }

    public function test_buscar_en_dgii_notifica_cuando_no_se_encuentra(): void
    {
        $this->app->bind(DgiiGatewayInterface::class, fn () => new class extends GatewayStub
        {
            public function buscarContribuyente(string $valor): ?array
            {
                return null;
            }
        });

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(CreateCliente::class)
            ->fillForm(['documento' => '130123456', 'nombre' => 'Nombre sin cambios'])
            ->callFormComponentAction('documento', 'buscarDgii')
            ->assertNotified()
            ->assertFormSet(['nombre' => 'Nombre sin cambios']);
    }
}
