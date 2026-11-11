<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Producto;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class HeladeriaVentasDiariasExporter extends Exporter
{
    protected static ?string $model = Producto::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('dia')
                ->label('Fecha'),
            ExportColumn::make('transacciones')
                ->label('Transacciones'),
            ExportColumn::make('unidades')
                ->label('Unidades'),
            ExportColumn::make('ingresos')
                ->label('Ingresos'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de ventas diarias heladería ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
