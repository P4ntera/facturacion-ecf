<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Enums\EstadoCompra;
use App\Enums\TipoComprobante;
use App\Filament\Exports\ReporteComprasExporter;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ReporteCompras extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Compras';

    protected static ?int $navigationSort = 44;

    protected static ?string $title = 'Reporte de compras';

    protected static ?string $slug = 'reportes/compras';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reportes.compras') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->comprasEnRangoQuery($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable(),

                TextColumn::make('ncf')
                    ->label('NCF')
                    ->placeholder('—'),

                TextColumn::make('tipo_comprobante')
                    ->label('Tipo')
                    ->formatStateUsing(fn (TipoComprobante $state) => $state->etiqueta()),

                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('DOP')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->where('estado', EstadoCompra::REGISTRADA->value)->sum('subtotal'))
                            ->money('DOP'),
                    ),

                TextColumn::make('itbis')
                    ->label('ITBIS')
                    ->money('DOP')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->where('estado', EstadoCompra::REGISTRADA->value)->sum('itbis'))
                            ->money('DOP'),
                    ),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total (no anuladas)')
                            ->using(fn (QueryBuilder $query) => $query->where('estado', EstadoCompra::REGISTRADA->value)->sum('total'))
                            ->money('DOP'),
                    ),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoCompra $state) => $state === EstadoCompra::ANULADA ? 'danger' : 'success'),

                TextColumn::make('user.name')
                    ->label('Registrado por'),
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

                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoCompra::class),

                SelectFilter::make('tipo_comprobante')
                    ->label('Tipo Comprobante')
                    ->options(TipoComprobante::class),
            ])
            ->defaultSort('fecha', 'desc');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.compras.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return ReporteComprasExporter::class;
    }
}
