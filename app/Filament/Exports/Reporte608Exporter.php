<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Venta;
use App\Services\ReporteService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class Reporte608Exporter extends Exporter
{
    protected static ?string $model = Venta::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('ncf')
                ->label('NCF anulado'),
            ExportColumn::make('tipo_comprobante')
                ->label('Tipo comprobante')
                ->formatStateUsing(fn ($state) => $state?->etiqueta() ?? '—'),
            ExportColumn::make('tipo_anulacion_608')
                ->label('Tipo anulación')
                ->formatStateUsing(fn (?string $state) => $state
                    ? ($state . ' - ' . (ReporteService::TIPO_ANULACION_608[$state] ?? $state))
                    : '—'),
            ExportColumn::make('anulada_en')
                ->label('Fecha anulación')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('motivo_anulacion')
                ->label('Motivo'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación del 608 ha finalizado y ' . Number::format($export->successful_rows) . ' ' . str('fila')->plural($export->successful_rows) . ' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('fila')->plural($failedRowsCount) . ' fallaron al exportar.';
        }

        return $body;
    }
}
