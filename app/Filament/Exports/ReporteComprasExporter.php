<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\EstadoCompra;
use App\Models\Compra;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class ReporteComprasExporter extends Exporter
{
    protected static ?string $model = Compra::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('fecha')
                ->label('Fecha')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('proveedor.nombre')
                ->label('Proveedor'),
            ExportColumn::make('ncf')
                ->label('NCF'),
            ExportColumn::make('tipo_comprobante')
                ->label('Tipo')
                ->getStateUsing(fn (Compra $record) => $record->tipo_comprobante?->etiqueta()),
            ExportColumn::make('subtotal')
                ->label('Subtotal'),
            ExportColumn::make('itbis')
                ->label('ITBIS'),
            ExportColumn::make('total')
                ->label('Total'),
            ExportColumn::make('estado')
                ->label('Estado')
                ->formatStateUsing(fn (EstadoCompra $state) => $state === EstadoCompra::ANULADA ? 'Anulada' : 'Registrada'),
            ExportColumn::make('user.name')
                ->label('Registrado por'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de compras ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
