<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CuentaPorCobrar;
use App\Services\ReporteService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Fila de KPIs del dashboard (diseño aprobado): ventas de hoy, facturas emitidas hoy
 * (electrónicas vs. físicas), CxC pendiente y stock bajo mínimo. Va antes que
 * ReporteStatsOverviewWidget (agregados mensuales) porque es la primera lectura del día.
 */
class DashboardStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    public static function canView(): bool
    {
        return auth()->user()?->canAny(['ventas.ver', 'cxc.ver', 'productos.ver']) ?? false;
    }

    protected function getStats(): array
    {
        $servicio = app(ReporteService::class);

        return [
            $this->statVentasHoy($servicio),
            $this->statFacturasEmitidas($servicio),
            $this->statCxcPendiente(),
            $this->statStockBajo($servicio),
        ];
    }

    private function statVentasHoy(ReporteService $servicio): Stat
    {
        $hoy = $servicio->ventasPorRango(Carbon::today(), Carbon::today());
        $ayer = $servicio->ventasPorRango(Carbon::yesterday(), Carbon::yesterday());

        $totalHoy = (float) $hoy['total_vendido'];
        $totalAyer = (float) $ayer['total_vendido'];
        $variacion = $totalAyer > 0 ? round((($totalHoy - $totalAyer) / $totalAyer) * 100, 1) : null;

        $descripcion = $variacion === null
            ? $hoy['cantidad_ventas'].' venta(s)'
            : ($variacion >= 0 ? '+' : '').$variacion.'% vs ayer';

        return Stat::make('Ventas Hoy', Number::currency($totalHoy, 'DOP'))
            ->description($descripcion)
            ->descriptionIcon(($variacion ?? 0) < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
            ->color(($variacion ?? 0) < 0 ? 'danger' : 'success');
    }

    private function statFacturasEmitidas(ReporteService $servicio): Stat
    {
        $desglose = $servicio->desgloseComprobantes(Carbon::today(), Carbon::today());
        $total = $desglose['electronicos'] + $desglose['fisicos'];

        return Stat::make('Facturas Emitidas', (string) $total)
            ->description("{$desglose['electronicos']} e-CF · {$desglose['fisicos']} tipo B")
            ->descriptionIcon('heroicon-m-document-text')
            ->color('primary');
    }

    private function statCxcPendiente(): Stat
    {
        $pendientes = CuentaPorCobrar::query()
            ->whereColumn('monto_pagado', '<', 'monto_total')
            ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as monto_pendiente')
            ->selectRaw('COUNT(*) as cantidad')
            ->first();

        $cantidad = (int) $pendientes->cantidad;

        return Stat::make('CxC Pendiente', Number::currency((float) $pendientes->monto_pendiente, 'DOP'))
            ->description($cantidad.' factura(s) abierta(s)')
            ->descriptionIcon('heroicon-m-clock')
            ->color($cantidad > 0 ? 'warning' : 'gray');
    }

    private function statStockBajo(ReporteService $servicio): Stat
    {
        $cantidad = $servicio->productosBajoMinimoQuery()->count();

        return Stat::make('Stock Bajo Mínimo', (string) $cantidad)
            ->description($cantidad > 0 ? 'Requieren reposición' : 'Todo en orden')
            ->descriptionIcon($cantidad > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->color($cantidad > 0 ? 'danger' : 'success');
    }
}
