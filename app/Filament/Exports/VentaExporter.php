<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\EstadoVenta;
use App\Filament\Resources\VentaResource;
use App\Models\Venta;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class VentaExporter extends Exporter
{
    protected static ?string $model = Venta::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('fecha')
                ->label('Fecha')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y H:i')),
            ExportColumn::make('ncf')
                ->label('e-NCF'),
            ExportColumn::make('cliente.nombre')
                ->label('Cliente'),
            ExportColumn::make('tipo_comprobante')
                ->label('Tipo')
                ->formatStateUsing(fn ($state) => $state?->etiqueta()),
            ExportColumn::make('subtotal')
                ->label('Subtotal'),
            ExportColumn::make('total_itbis')
                ->label('ITBIS'),
            ExportColumn::make('total')
                ->label('Total'),
            ExportColumn::make('estado')
                ->label('Estado')
                ->formatStateUsing(fn (EstadoVenta $state) => $state === EstadoVenta::ANULADA ? 'Anulada' : 'Emitida'),
            ExportColumn::make('estado_fiscal')
                ->label('Estado fiscal')
                ->formatStateUsing(fn ($state) => VentaResource::etiquetaEstadoFiscal($state)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de ventas ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
