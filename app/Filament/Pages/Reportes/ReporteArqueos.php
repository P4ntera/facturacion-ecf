<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Enums\EstadoArqueoCaja;
use App\Filament\Exports\ReporteArqueosExporter;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReporteArqueos extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Arqueos de Caja';

    protected static ?int $navigationSort = 45;

    protected static ?string $title = 'Reporte de arqueos de caja';

    protected static ?string $slug = 'reportes/arqueos';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reportes.arqueos') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->arqueosEnRangoQuery($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('abierto_en')
                    ->label('Apertura')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('cerrado_en')
                    ->label('Cierre')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                TextColumn::make('user.name')
                    ->label('Cajero')
                    ->searchable(),

                TextColumn::make('caja.nombre')
                    ->label('Caja'),

                TextColumn::make('fondo_inicial')
                    ->label('Fondo Inicial')
                    ->money('DOP'),

                TextColumn::make('total_ventas_efectivo')
                    ->label('Efectivo')
                    ->money('DOP'),

                TextColumn::make('total_ventas_tarjeta')
                    ->label('Tarjeta')
                    ->money('DOP'),

                TextColumn::make('total_ventas_transferencia')
                    ->label('Transferencia')
                    ->money('DOP'),

                TextColumn::make('efectivo_esperado')
                    ->label('Esperado')
                    ->money('DOP'),

                TextColumn::make('efectivo_contado')
                    ->label('Contado')
                    ->money('DOP'),

                TextColumn::make('diferencia')
                    ->label('Diferencia')
                    ->money('DOP')
                    ->color(fn ($state) => match (true) {
                        (float) $state < 0 => 'danger',
                        (float) $state > 0 => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoArqueoCaja $state) => $state === EstadoArqueoCaja::ABIERTO ? 'warning' : 'success'),
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

                SelectFilter::make('user_id')
                    ->label('Cajero')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoArqueoCaja::class),

                SelectFilter::make('caja_id')
                    ->label('Caja')
                    ->relationship('caja', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('abierto_en', 'desc');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.arqueos.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return ReporteArqueosExporter::class;
    }
}
