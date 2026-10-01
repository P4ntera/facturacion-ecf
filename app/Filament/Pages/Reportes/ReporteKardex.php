<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Filament\Exports\ReporteKardexExporter;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReporteKardex extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?string $navigationLabel = 'Kardex';

    protected static ?int $navigationSort = 51;

    protected static ?string $title = 'Kardex de movimientos';

    protected static ?string $slug = 'reportes/kardex';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reportes.kardex') ?? false;
    }

    public function table(Table $table): Table
    {
        $productoId = isset($this->tableFilters['producto_id']['value'])
            ? (int) $this->tableFilters['producto_id']['value'] ?: null
            : null;

        return $table
            ->query(fn () => app(ReporteService::class)->kardexQuery($this->rangoDesde(), $this->rangoHasta(), $productoId))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (TipoMovimiento $state) => match ($state) {
                        TipoMovimiento::ENTRADA => 'success',
                        TipoMovimiento::SALIDA => 'danger',
                        TipoMovimiento::AJUSTE => 'warning',
                    }),

                TextColumn::make('origen')
                    ->label('Origen')
                    ->badge(),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric()
                    ->formatStateUsing(fn ($state, $record) => $record->tipo === TipoMovimiento::SALIDA
                        ? '-'.number_format((float) $state, 2)
                        : '+'.number_format((float) $state, 2)
                    )
                    ->color(fn ($record) => $record->tipo === TipoMovimiento::SALIDA ? 'danger' : 'success'),

                TextColumn::make('stock_anterior')
                    ->label('Stock Anterior')
                    ->numeric(),

                TextColumn::make('stock_nuevo')
                    ->label('Stock Nuevo')
                    ->numeric(),

                TextColumn::make('user.name')
                    ->label('Usuario'),

                TextColumn::make('observacion')
                    ->label('Observación')
                    ->limit(40)
                    ->placeholder('—'),
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

                SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoMovimiento::class),

                SelectFilter::make('origen')
                    ->label('Origen')
                    ->options(OrigenMovimiento::class),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.kardex.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return ReporteKardexExporter::class;
    }
}
