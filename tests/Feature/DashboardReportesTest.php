<?php

namespace Tests\Feature;

use App\Filament\Widgets\ReporteStatsOverviewWidget;
use App\Filament\Widgets\TopProductosWidget;
use App\Filament\Widgets\VentasPorDiaChartWidget;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardReportesTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    public function test_el_dashboard_carga_y_registra_los_widgets_de_reportes(): void
    {
        $usuario = $this->usuarioConPermiso();

        $this->actingAs($usuario)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeLivewire(ReporteStatsOverviewWidget::class)
            ->assertSeeLivewire(VentasPorDiaChartWidget::class)
            ->assertSeeLivewire(TopProductosWidget::class);
    }

    public function test_el_widget_de_kpis_muestra_ventas_de_hoy_para_quien_tiene_ver_reportes(): void
    {
        $usuario = $this->usuarioConPermiso();

        Livewire::actingAs($usuario)
            ->test(ReporteStatsOverviewWidget::class)
            ->assertSuccessful()
            ->assertSee('Ventas de hoy')
            ->assertSee('Productos bajo mínimo');
    }

    public function test_usuario_sin_ver_reportes_no_ve_los_widgets_de_reportes_en_el_dashboard(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Almacenista');

        $this->actingAs($usuario)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertDontSeeLivewire(ReporteStatsOverviewWidget::class)
            ->assertDontSeeLivewire(VentasPorDiaChartWidget::class)
            ->assertDontSeeLivewire(TopProductosWidget::class);
    }
}
