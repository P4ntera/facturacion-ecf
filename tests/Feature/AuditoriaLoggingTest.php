<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditoriaLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_y_editar_un_producto_registra_actividad_con_causante_y_modulo(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $producto = Producto::create([
            'codigo' => 'AUD-1', 'nombre' => 'Producto auditado', 'tipo' => 'producto',
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => '18',
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $producto->update(['precio' => 999]);

        $actividades = Activity::where('subject_type', Producto::class)
            ->where('subject_id', $producto->id)
            ->get();

        $this->assertSame(2, $actividades->count());
        $this->assertSame('Productos', $actividades->first()->log_name);
        $this->assertSame($usuario->id, $actividades->last()->causer_id);
        $this->assertSame('updated', $actividades->last()->event);
        $this->assertArrayHasKey('precio', $actividades->last()->properties['attributes']);
    }

    public function test_un_update_sin_cambios_reales_no_genera_actividad(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $producto = Producto::create([
            'codigo' => 'AUD-2', 'nombre' => 'Producto sin cambios', 'tipo' => 'producto',
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => '18',
            'controla_stock' => false, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $antes = Activity::where('subject_type', Producto::class)->where('subject_id', $producto->id)->count();

        $producto->update(['precio' => 2]); // mismo valor: no debe loguear

        $despues = Activity::where('subject_type', Producto::class)->where('subject_id', $producto->id)->count();

        $this->assertSame($antes, $despues);
    }
}
