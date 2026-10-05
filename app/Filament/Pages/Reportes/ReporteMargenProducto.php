<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\ReporteMargenProductoExporter;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Facades\Filament;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReporteMargenProducto extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Margen por Producto';

    protected static string|\UnitEnum|null $navigationGroup = 'Financieros';

    protected static ?int $navigationSort = 57;

    protected static ?string $title = 'Margen por producto';

    protected static ?string $slug = 'reportes/margen-producto';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reportes.margen') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->margenPorProductoQuery($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código'),

                TextColumn::make('nombre')
                    ->label('Producto')
                    ->searchable(),

                TextColumn::make('unidades_vendidas')
                    ->label('Unidades')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('costo')
                    ->label('Costo Unitario')
                    ->money('DOP'),

                TextColumn::make('ingresos')
                    ->label('Ingresos')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('costo_total')
                    ->label('Costo Total')
                    ->money('DOP'),

                TextColumn::make('ganancia')
                    ->label('Ganancia')
                    ->money('DOP')
                    ->sortable()
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),

                TextColumn::make('margen_porcentaje')
                    ->label('Margen %')
                    ->suffix('%')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        (float) $state >= 30 => 'success',
                        (float) $state >= 15 => 'warning',
                        default => 'danger',
                    }),
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

                SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre', modifyQueryUsing: fn ($query) => $query->where('empresa_id', Filament::getTenant()->id))
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('margen_porcentaje', 'desc')
            ->defaultKeySort(false);
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.margen.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return ReporteMargenProductoExporter::class;
    }
}
