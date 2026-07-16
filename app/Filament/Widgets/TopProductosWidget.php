<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\ReporteService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TopProductosWidget extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('ver_reportes') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top 5 productos del mes (por ingresos)')
            ->records(function (): Collection {
                $productos = app(ReporteService::class)->topProductos(
                    Carbon::today()->startOfMonth(),
                    Carbon::today()->endOfMonth(),
                    5,
                )['por_ingresos'];

                return $productos->map(fn ($producto) => [
                    'id' => $producto->id,
                    'codigo' => $producto->codigo,
                    'nombre' => $producto->nombre,
                    'cantidad_vendida' => $producto->cantidad_vendida,
                    'ingresos' => $producto->ingresos,
                ]);
            })
            ->columns([
                TextColumn::make('codigo')->label('Código'),
                TextColumn::make('nombre')->label('Producto'),
                TextColumn::make('cantidad_vendida')->label('Cantidad vendida')->numeric(),
                TextColumn::make('ingresos')->label('Ingresos')->money('DOP'),
            ])
            ->paginated(false);
    }
}
