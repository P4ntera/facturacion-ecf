<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Filament\Resources\VentaResource;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ReporteVentas extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Ventas';

    protected static ?string $title = 'Reporte de ventas';

    protected static ?string $slug = 'reportes/ventas';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->ventasEnRangoQuery($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('ncf')
                    ->label('e-NCF')
                    ->placeholder('—'),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable(),

                TextColumn::make('tipo_comprobante')
                    ->label('Tipo')
                    ->formatStateUsing(fn (TipoComprobante $state) => $state->etiqueta()),

                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('DOP')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->where('estado', EstadoVenta::EMITIDA->value)->sum('subtotal'))
                            ->money('DOP'),
                    ),

                TextColumn::make('total_itbis')
                    ->label('ITBIS')
                    ->money('DOP')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->where('estado', EstadoVenta::EMITIDA->value)->sum('total_itbis'))
                            ->money('DOP'),
                    ),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total (no anuladas)')
                            ->using(fn (QueryBuilder $query) => $query->where('estado', EstadoVenta::EMITIDA->value)->sum('total'))
                            ->money('DOP'),
                    ),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoVenta $state) => $state === EstadoVenta::ANULADA ? 'danger' : 'success'),

                TextColumn::make('estado_fiscal')
                    ->label('Estado fiscal')
                    ->badge()
                    ->formatStateUsing(fn (EstadoFiscal $state) => VentaResource::etiquetaEstadoFiscal($state))
                    ->color(fn (EstadoFiscal $state) => VentaResource::colorEstadoFiscal($state)),
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
            ->defaultSort('fecha', 'desc');
    }
}
