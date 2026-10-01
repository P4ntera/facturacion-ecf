<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\ReporteCuentasPorPagarExporter;
use App\Models\CuentaPorPagar;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ReporteCuentasPorPagar extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|\UnitEnum|null $navigationGroup = 'Financieros';

    protected static ?string $navigationLabel = 'Cuentas por Pagar';

    protected static ?int $navigationSort = 56;

    protected static ?string $title = 'Reporte de cuentas por pagar';

    protected static ?string $slug = 'reportes/cuentas-por-pagar';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reportes.cxp') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->cuentasPorPagarQuery())
            ->columns([
                TextColumn::make('fecha_emision')
                    ->label('Emisión')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable(),

                TextColumn::make('compra.ncf')
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
                    ->getStateUsing(fn (CuentaPorPagar $record) => $record->montoPendiente())
                    ->summarize(
                        Summarizer::make()
                            ->label('Total pendiente')
                            ->using(fn (QueryBuilder $query) => $query->selectRaw('SUM(monto_total - monto_pagado)')->value('sum'))
                            ->money('DOP'),
                    ),

                TextColumn::make('antiguedad')
                    ->label('Antigüedad')
                    ->badge()
                    ->getStateUsing(function (CuentaPorPagar $record): string {
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
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
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
        return 'reportes.cxp.pdf';
    }

    protected function exporterClass(): string
    {
        return ReporteCuentasPorPagarExporter::class;
    }
}
