<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\DetalleTicketLavanderia;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class ProduccionServicioExporter extends Exporter
{
    protected static ?string $model = DetalleTicketLavanderia::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('servicio')
                ->label('Servicio'),
            ExportColumn::make('tipo_prenda')
                ->label('Tipo de Prenda'),
            ExportColumn::make('total_prendas')
                ->label('Total Prendas'),
            ExportColumn::make('total_ingresos')
                ->label('Total Ingresos'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de producción por servicio ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
