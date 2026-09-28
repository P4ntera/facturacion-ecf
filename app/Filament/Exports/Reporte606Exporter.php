<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Compra;
use App\Services\ReporteService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class Reporte606Exporter extends Exporter
{
    protected static ?string $model = Compra::class;

    public static function getColumns(): array
    {
        $servicio = app(ReporteService::class);

        return [
            ExportColumn::make('fecha')
                ->label('Fecha comprobante')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('ncf')
                ->label('NCF proveedor'),
            ExportColumn::make('proveedor.rnc')
                ->label('RNC/Cédula proveedor'),
            ExportColumn::make('tipo_identificacion')
                ->label('Tipo identificación')
                ->getStateUsing(fn (Compra $record) => $servicio->tipoIdentificacion606($record->proveedor?->rnc)),
            ExportColumn::make('proveedor.nombre')
                ->label('Proveedor'),
            ExportColumn::make('tipo_bienes_servicios_606')
                ->label('Tipo bienes/servicios')
                ->formatStateUsing(fn (?string $state) => ReporteService::TIPO_BIENES_SERVICIOS_606[$state] ?? $state),
            ExportColumn::make('subtotal')
                ->label('Monto facturado'),
            ExportColumn::make('itbis')
                ->label('ITBIS facturado'),
            ExportColumn::make('total')
                ->label('Monto total'),
            ExportColumn::make('retencion_itbis')
                ->label('ITBIS retenido'),
            ExportColumn::make('retencion_isr')
                ->label('Retención ISR'),
            ExportColumn::make('forma_pago_606')
                ->label('Forma de pago')
                ->formatStateUsing(fn (?string $state) => ReporteService::FORMA_PAGO_606[$state] ?? $state),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación del 606 ha finalizado y ' . Number::format($export->successful_rows) . ' ' . str('fila')->plural($export->successful_rows) . ' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('fila')->plural($failedRowsCount) . ' fallaron al exportar.';
        }

        return $body;
    }
}
