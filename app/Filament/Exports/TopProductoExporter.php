<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Producto;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class TopProductoExporter extends Exporter
{
    protected static ?string $model = Producto::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('codigo')
                ->label('Código'),
            ExportColumn::make('nombre')
                ->label('Producto'),
            ExportColumn::make('cantidad_vendida')
                ->label('Cantidad vendida'),
            ExportColumn::make('ingresos')
                ->label('Ingresos'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de top productos ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
