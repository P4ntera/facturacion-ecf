<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\CuentaPorPagar;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class ReporteCuentasPorPagarExporter extends Exporter
{
    protected static ?string $model = CuentaPorPagar::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('fecha_emision')
                ->label('Emisión')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('fecha_vencimiento')
                ->label('Vencimiento')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('proveedor.nombre')
                ->label('Proveedor'),
            ExportColumn::make('compra.ncf')
                ->label('NCF'),
            ExportColumn::make('monto_total')
                ->label('Monto Total'),
            ExportColumn::make('monto_pagado')
                ->label('Pagado'),
            ExportColumn::make('saldo_pendiente')
                ->label('Saldo Pendiente')
                ->getStateUsing(fn (CuentaPorPagar $record) => $record->montoPendiente()),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de cuentas por pagar ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
