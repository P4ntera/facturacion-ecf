<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class ReporteTopProductos extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Top Productos';

    protected static ?string $title = 'Top de productos';

    protected static ?string $slug = 'reportes/top-productos';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->productosVendidosQuery($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código'),

                TextColumn::make('nombre')
                    ->label('Producto')
                    ->searchable(),

                TextColumn::make('cantidad_vendida')
                    ->label('Cantidad vendida')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('ingresos')
                    ->label('Ingresos')
                    ->money('DOP')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('rango')
                    ->schema([
                        DatePicker::make('desde')
                            ->label('Desde')
                            ->default(fn () => now()->startOfMonth()->toDateString()),
                        DatePicker::make('hasta')
                            ->label('Hasta')
                            ->default(fn () => now()->endOfMonth()->toDateString()),
                    ]),
            ])
            ->defaultSort('ingresos', 'desc');
    }
}
