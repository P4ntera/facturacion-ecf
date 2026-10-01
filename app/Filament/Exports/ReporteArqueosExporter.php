<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\ArqueoCaja;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class ReporteArqueosExporter extends Exporter
{
    protected static ?string $model = ArqueoCaja::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('abierto_en')
                ->label('Apertura')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y H:i')),
            ExportColumn::make('cerrado_en')
                ->label('Cierre')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y H:i')),
            ExportColumn::make('user.name')
                ->label('Cajero'),
            ExportColumn::make('caja.nombre')
                ->label('Caja'),
            ExportColumn::make('fondo_inicial')
                ->label('Fondo Inicial'),
            ExportColumn::make('total_ventas_efectivo')
                ->label('Efectivo'),
            ExportColumn::make('total_ventas_tarjeta')
                ->label('Tarjeta'),
            ExportColumn::make('total_ventas_transferencia')
                ->label('Transferencia'),
            ExportColumn::make('efectivo_esperado')
                ->label('Esperado'),
            ExportColumn::make('efectivo_contado')
                ->label('Contado'),
            ExportColumn::make('diferencia')
                ->label('Diferencia'),
            ExportColumn::make('estado')
                ->label('Estado')
                ->getStateUsing(fn (ArqueoCaja $record) => $record->estado->etiqueta()),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de arqueos ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
