<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\ReporteCuentasPorCobrarExporter;
use App\Models\CuentaPorCobrar;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ReporteCuentasPorCobrar extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Financieros';

    protected static ?string $navigationLabel = 'Cuentas por Cobrar';

    protected static ?int $navigationSort = 55;

    protected static ?string $title = 'Reporte de cuentas por cobrar';

    protected static ?string $slug = 'reportes/cuentas-por-cobrar';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reportes.cxc') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->cuentasPorCobrarQuery())
            ->columns([
                TextColumn::make('fecha_emision')
                    ->label('Emisión')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable(),

                TextColumn::make('venta.ncf')
                    ->label('NCF')
                    ->placeholder('—'),

                TextColumn::make('monto_total')
                    ->label('Monto Total')
                    ->money('DOP'),

                TextColumn::make('monto_pagado')
                    ->label('Pagado')
                    ->money('DOP'),

                TextColumn::make('saldo_pendiente')
                    ->label('Saldo Pendiente')
                    ->money('DOP')
                    ->getStateUsing(fn (CuentaPorCobrar $record) => $record->montoPendiente())
                    ->summarize(
                        Summarizer::make()
                            ->label('Total pendiente')
                            ->using(fn (QueryBuilder $query) => $query->selectRaw('SUM(monto_total - monto_pagado)')->value('sum'))
                            ->money('DOP'),
                    ),

                TextColumn::make('antiguedad')
                    ->label('Antigüedad')
                    ->badge()
                    ->getStateUsing(function (CuentaPorCobrar $record): string {
                        $dias = (int) $record->fecha_emision->diffInDays(today());

                        return match (true) {
                            $dias <= 30 => 'Corriente',
                            $dias <= 60 => '30+ días',
                            $dias <= 90 => '60+ días',
                            default => '90+ días',
                        };
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Corriente' => 'success',
                        '30+ días' => 'warning',
                        '60+ días' => 'danger',
                        default => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('antiguedad')
                    ->label('Antigüedad')
                    ->options([
                        'corriente' => 'Corriente (0–30 días)',
                        '30plus' => '30+ días',
                        '60plus' => '60+ días',
                        '90plus' => '90+ días',
                    ])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'corriente' => $query->whereRaw("fecha_emision >= (CURRENT_DATE - INTERVAL '30 days')"),
                        '30plus' => $query->whereRaw("fecha_emision < (CURRENT_DATE - INTERVAL '30 days')"),
                        '60plus' => $query->whereRaw("fecha_emision < (CURRENT_DATE - INTERVAL '60 days')"),
                        '90plus' => $query->whereRaw("fecha_emision < (CURRENT_DATE - INTERVAL '90 days')"),
                        default => $query,
                    }),

                TernaryFilter::make('vencidas')
                    ->label('Vencimiento')
                    ->placeholder('Todas')
                    ->trueLabel('Solo vencidas')
                    ->falseLabel('Solo vigentes')
                    ->queries(
                        true: fn ($q) => $q->where('fecha_vencimiento', '<', today()),
                        false: fn ($q) => $q->where('fecha_vencimiento', '>=', today()),
                    ),
            ])
            ->defaultSort('fecha_vencimiento', 'asc');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.cxc.pdf';
    }

    protected function exporterClass(): string
    {
        return ReporteCuentasPorCobrarExporter::class;
    }
}
