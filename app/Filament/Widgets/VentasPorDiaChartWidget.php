<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\ReporteService;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class VentasPorDiaChartWidget extends ChartWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'Ventas por día (últimos 30 días)';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('ver_reportes') ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $desde = Carbon::today()->subDays(29);
        $hasta = Carbon::today();

        $serie = app(ReporteService::class)->ventasPorDia($desde, $hasta);

        $etiquetas = [];
        $totales = [];

        for ($dia = $desde->copy(); $dia->lte($hasta); $dia->addDay()) {
            $clave = $dia->toDateString();
            $etiquetas[] = $dia->format('d/m');
            $totales[] = (float) ($serie->get($clave) ?? '0.00');
        }

        return [
            'datasets' => [
                [
                    'label' => 'Ventas',
                    'data' => $totales,
                    'borderColor' => '#2563EB',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $etiquetas,
        ];
    }
}
