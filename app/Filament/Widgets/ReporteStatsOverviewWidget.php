<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\ReporteService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class ReporteStatsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    public static function canView(): bool
    {
        return auth()->user()?->can('reportes.ver') ?? false;
    }

    protected function getStats(): array
    {
        $servicio = app(ReporteService::class);

        $hoy = $servicio->ventasPorRango(Carbon::today(), Carbon::today());
        $mes = $servicio->ventasPorRango(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth());
        $productosBajoMinimo = $servicio->productosBajoMinimo()->count();

        return [
            Stat::make('Ventas de hoy', Number::currency((float) $hoy['total_vendido'], 'DOP'))
                ->description($hoy['cantidad_ventas'].' venta(s)')
                ->color('success'),

            Stat::make('Ventas del mes', Number::currency((float) $mes['total_vendido'], 'DOP'))
                ->description($mes['cantidad_ventas'].' venta(s)')
                ->color('primary'),

            Stat::make('ITBIS del mes', Number::currency((float) $mes['total_itbis'], 'DOP'))
                ->color('info'),

            Stat::make('Valor del inventario', Number::currency((float) $servicio->valorInventario(), 'DOP'))
                ->color('gray'),

            Stat::make('Productos bajo mínimo', (string) $productosBajoMinimo)
                ->description($productosBajoMinimo > 0 ? 'Requieren reposición' : 'Todo en orden')
                ->color($productosBajoMinimo > 0 ? 'danger' : 'success'),
        ];
    }
}
