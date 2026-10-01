<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Producto;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class ReporteMargenProductoExporter extends Exporter
{
    protected static ?string $model = Producto::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('codigo')
                ->label('Código'),
            ExportColumn::make('nombre')
                ->label('Producto'),
            ExportColumn::make('unidades_vendidas')
                ->label('Unidades'),
            ExportColumn::make('costo')
                ->label('Costo Unitario'),
            ExportColumn::make('ingresos')
                ->label('Ingresos'),
            ExportColumn::make('costo_total')
                ->label('Costo Total'),
            ExportColumn::make('ganancia')
                ->label('Ganancia'),
            ExportColumn::make('margen_porcentaje')
                ->label('Margen %'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de margen por producto ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
