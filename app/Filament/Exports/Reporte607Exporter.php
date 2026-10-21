<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Venta;
use App\Services\ReporteService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Exportador Excel/CSV del 607 para revisión interna. El TXT oficial de 23 columnas para la
 * Oficina Virtual de la DGII se genera en ReporteService::reporte607Txt() y se descarga desde
 * el botón "Exportar TXT (DGII)" en la página ReporteFiscal607.
 */
class Reporte607Exporter extends Exporter
{
    protected static ?string $model = Venta::class;

    public static function getColumns(): array
    {
        $servicio = app(ReporteService::class);

        return [
            ExportColumn::make('fecha')
                ->label('Fecha comprobante')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('ncf')
                ->label('Número comprobante'),
            ExportColumn::make('ncf_modifica')
                ->label('Número comprobante modificado'),
            ExportColumn::make('rnc_cedula')
                ->label('RNC/Cédula')
                ->getStateUsing(fn (Venta $record) => $servicio->rncCedula607($record->cliente?->tipo_documento, $record->cliente?->documento)),
            ExportColumn::make('tipo_identificacion')
                ->label('Tipo identificación')
                ->getStateUsing(fn (Venta $record) => $servicio->tipoIdentificacion607($record->cliente?->tipo_documento)),
            ExportColumn::make('tipo_ingreso')
                ->label('Tipo de ingreso')
                ->getStateUsing(fn () => ReporteService::TIPO_INGRESO_DEFECTO),
            ExportColumn::make('subtotal')
                ->label('Monto facturado')
                ->getStateUsing(fn (Venta $record) => $record->subtotalNeto()),
            ExportColumn::make('total_itbis')
                ->label('ITBIS facturado'),
            ExportColumn::make('total')
                ->label('Monto total'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación del 607 ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
