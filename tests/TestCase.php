<?php

namespace Tests;

use App\Models\Empresa;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TenantDefaults;

abstract class TestCase extends BaseTestCase
{
    protected ?Empresa $empresaDefault = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        $this->empresaDefault = Empresa::factory()->create();
        TenantDefaults::reiniciar($this->empresaDefault);

        // Sin esto, cualquier Livewire::test()/Livewire::actingAs() sobre un Resource o Page del
        // panel falla al generar URLs con {tenant} (Filament lo resuelve normalmente desde la
        // URL real vía middleware, algo que Livewire::test() no ejecuta).
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->empresaDefault, isQuiet: true);

        // Roles con 'teams' => true: sin un contexto de permisos activo, assignRole()/hasRole()
        // no encuentran nada. Los tests existentes (anteriores a roles-por-empresa) llaman
        // $this->seed(RolePermissionSeeder::class) y luego $usuario->assignRole('X') sin saber
        // de tenancy; RolePermissionSeeder siembra los roles base de toda empresa ya existente
        // (aquí, empresaDefault), así que ambos quedan alineados sin tocar cada test.
        setPermissionsTeamId($this->empresaDefault->id);
    }
}
