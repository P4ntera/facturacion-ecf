<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Enums\Modulo;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class ReporteHeladeriaTopProductos extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Top Productos Heladería';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Top de productos de heladería';

    protected static ?string $slug = 'reportes/heladeria-top-productos';

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
            ->query(fn () => app(ReporteService::class)->heladeriaProductosVendidosQuery(
                $this->rangoDesde(),
                $this->rangoHasta(),
                Filament::getTenant()->id,
            ))
            ->columns([
                TextColumn::make('nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state) => str_replace('Heladería: ', '', $state)),

                TextColumn::make('cantidad_vendida')
                    ->label('Cantidad')
                    ->numeric()
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->numeric()),

                TextColumn::make('ingresos')
                    ->label('Ingresos')
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
            ->defaultSort('ingresos', 'desc');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.heladeria-top-productos.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return \App\Filament\Exports\HeladeriaTopProductoExporter::class;
    }
}
