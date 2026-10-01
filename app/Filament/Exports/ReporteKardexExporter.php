<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\MovimientoInventario;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class ReporteKardexExporter extends Exporter
{
    protected static ?string $model = MovimientoInventario::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('created_at')
                ->label('Fecha')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y H:i')),
            ExportColumn::make('producto.nombre')
                ->label('Producto'),
            ExportColumn::make('tipo')
                ->label('Tipo')
                ->getStateUsing(fn (MovimientoInventario $record) => $record->tipo->value),
            ExportColumn::make('origen')
                ->label('Origen')
                ->getStateUsing(fn (MovimientoInventario $record) => $record->origen->value),
            ExportColumn::make('cantidad')
                ->label('Cantidad'),
            ExportColumn::make('stock_anterior')
                ->label('Stock Anterior'),
            ExportColumn::make('stock_nuevo')
                ->label('Stock Nuevo'),
            ExportColumn::make('user.name')
                ->label('Usuario'),
            ExportColumn::make('observacion')
                ->label('Observación'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación del kardex ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
