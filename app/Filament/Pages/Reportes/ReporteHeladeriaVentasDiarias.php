<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Enums\Modulo;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ReporteHeladeriaVentasDiarias extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Ventas Diarias';

    protected static ?int $navigationSort = 92;

    protected static ?string $title = 'Ventas diarias de heladería';

    protected static ?string $slug = 'reportes/heladeria-ventas-diarias';

    protected static string|\UnitEnum|null $navigationGroup = 'Heladería';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->can('heladeria.ver_reportes') ?? false)
            && (Filament::getTenant()?->tieneModulo(Modulo::HELADERIA_SABORES) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->heladeriaVentasDiariasQuery(
                $this->rangoDesde(),
                $this->rangoHasta(),
                Filament::getTenant()->id,
            ))
            ->paginated(false)
            ->columns([
                TextColumn::make('dia')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('transacciones')
                    ->label('Transacciones')
                    ->numeric()
                    ->sortable()
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->sum('transacciones'))
                            ->numeric(),
                    ),

                TextColumn::make('unidades')
                    ->label('Unidades')
                    ->numeric()
                    ->sortable()
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->sum('unidades'))
                            ->numeric(),
                    ),

                TextColumn::make('ingresos')
                    ->label('Ingresos')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->sum('ingresos'))
                            ->money('DOP'),
                    ),
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
            ->defaultSort('dia', 'asc');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.heladeria-ventas-diarias.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return \App\Filament\Exports\HeladeriaVentasDiariasExporter::class;
    }
}
