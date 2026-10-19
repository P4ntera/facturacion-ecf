<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\ReporteService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

class ReporteStatsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('reportes.ver') ?? false;
    }

    protected function getStats(): array
    {
        $empresaId = Filament::getTenant()?->id;
        $cacheKey = "dashboard_stats_{$empresaId}_" . Carbon::today()->toDateString();
        $ttl = 300; // 5 minutos

        $servicio = app(ReporteService::class);

        $hoy = Cache::remember("{$cacheKey}_hoy", $ttl, fn () => $servicio->ventasPorRango(Carbon::today(), Carbon::today()));
        $mes = Cache::remember("{$cacheKey}_mes", $ttl, fn () => $servicio->ventasPorRango(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth()));
        $productosBajoMinimo = Cache::remember("{$cacheKey}_bajo_min", $ttl, fn () => $servicio->productosBajoMinimo()->count());
        $desglose = Cache::remember("{$cacheKey}_desglose", $ttl, fn () => $servicio->desgloseComprobantes(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth()));

        return [
            Stat::make('Ventas de hoy', Number::currency((float) $hoy['total_vendido'], 'DOP'))
                ->description($hoy['cantidad_ventas'].' venta(s)')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Ventas del mes', Number::currency((float) $mes['total_vendido'], 'DOP'))
                ->description($mes['cantidad_ventas'].' venta(s)')
                ->descriptionIcon('heroicon-o-shopping-bag')
                ->icon('heroicon-o-chart-bar')
                ->color('primary'),

            Stat::make('ITBIS del mes', Number::currency((float) $mes['total_itbis'], 'DOP'))
                ->icon('heroicon-o-receipt-percent')
                ->color('info'),

            Stat::make('Valor del inventario', Number::currency((float) Cache::remember("{$cacheKey}_inv", $ttl, fn () => $servicio->valorInventario()), 'DOP'))
                ->icon('heroicon-o-archive-box')
                ->color('gray'),

            Stat::make('Comprobantes del mes', "{$desglose['electronicos']} e-CF / {$desglose['fisicos']} tipo B")
                ->description('Electrónicos vs. físicos')
                ->descriptionIcon('heroicon-o-document-text')
                ->icon('heroicon-o-document-text')
                ->color('info'),

            Stat::make('Productos bajo mínimo', (string) $productosBajoMinimo)
                ->description($productosBajoMinimo > 0 ? 'Requieren reposición' : 'Todo en orden')
                ->descriptionIcon($productosBajoMinimo > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($productosBajoMinimo > 0 ? 'danger' : 'success'),
        ];
    }
}
