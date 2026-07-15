<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class ReporteVentasPorCliente extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Ventas por Cliente';

    protected static ?string $title = 'Ventas por cliente';

    protected static ?string $slug = 'reportes/ventas-por-cliente';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->ventasPorClienteQuery($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('cliente_nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cantidad_ventas')
                    ->label('Cantidad de ventas')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_vendido')
                    ->label('Total vendido')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('DOP')),
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
            ->defaultSort('total_vendido', 'desc')
            // La consulta agrupa por cliente, no por ventas.id: el desempate por clave
            // primaria que Filament añade por defecto rompería el GROUP BY de Postgres.
            ->defaultKeySort(false);
    }
}
