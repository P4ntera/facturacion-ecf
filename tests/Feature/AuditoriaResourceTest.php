<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditoriaResource\Pages\ListAuditorias;
use App\Filament\Resources\AuditoriaResource\Pages\ViewAuditoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AuditoriaResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_sin_permiso_no_puede_ver_la_auditoria(): void
    {
        $usuario = User::factory()->create();

        $this->assertFalse($usuario->can('viewAny', Activity::class));
    }

    public function test_usuario_con_permiso_ve_el_visor_y_el_detalle_con_antes_despues(): void
    {
        Permission::firstOrCreate(['name' => 'ver_auditoria', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'gestionar_maestros', 'guard_name' => 'web']);

        $usuario = User::factory()->create();
        $usuario->givePermissionTo(['ver_auditoria', 'gestionar_maestros']);

        $this->actingAs($usuario);

        $producto = Producto::create([
            'codigo' => 'AUD-3', 'nombre' => 'Producto', 'tipo' => 'producto',
            'costo' => 1, 'precio' => 10, 'tasa_itbis' => '18',
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);
        $producto->update(['precio' => 20]);

        $actividad = Activity::where('subject_id', $producto->id)->where('event', 'updated')->first();

        Livewire::actingAs($usuario)
            ->test(ListAuditorias::class)
            ->assertCanSeeTableRecords([$actividad])
            ->assertSuccessful();

        Livewire::actingAs($usuario)
            ->test(ViewAuditoria::class, ['record' => $actividad->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('precio');
    }
}
