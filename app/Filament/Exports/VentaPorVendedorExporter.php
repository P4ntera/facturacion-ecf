<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Venta;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class VentaPorVendedorExporter extends Exporter
{
    protected static ?string $model = Venta::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('user_nombre')
                ->label('Vendedor'),
            ExportColumn::make('cantidad_ventas')
                ->label('Cantidad de ventas'),
            ExportColumn::make('total_vendido')
                ->label('Total vendido'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de ventas por vendedor ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
