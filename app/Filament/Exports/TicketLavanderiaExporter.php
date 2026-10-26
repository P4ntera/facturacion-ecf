<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Models\TicketLavanderia;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class TicketLavanderiaExporter extends Exporter
{
    protected static ?string $model = TicketLavanderia::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('numero')
                ->label('Número'),
            ExportColumn::make('created_at')
                ->label('Fecha')
                ->formatStateUsing(fn ($state) => $state?->format('d/m/Y H:i')),
            ExportColumn::make('cliente')
                ->label('Cliente')
                ->getStateUsing(fn (TicketLavanderia $record) => $record->cliente?->nombre ?? 'Sin cliente'),
            ExportColumn::make('recepcionista')
                ->label('Recepcionista')
                ->getStateUsing(fn (TicketLavanderia $record) => $record->recepcionista?->nombre),
            ExportColumn::make('estado')
                ->label('Estado')
                ->formatStateUsing(fn (EstadoTicketLavanderia $state) => $state->etiqueta()),
            ExportColumn::make('estado_pago')
                ->label('Estado de Pago')
                ->formatStateUsing(fn (EstadoPagoTicket $state) => $state->etiqueta()),
            ExportColumn::make('itbis')
                ->label('ITBIS'),
            ExportColumn::make('total')
                ->label('Total'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'La exportación de tickets de lavandería ha finalizado y '.Number::format($export->successful_rows).' '.str('fila')->plural($export->successful_rows).' se exportaron.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('fila')->plural($failedRowsCount).' fallaron al exportar.';
        }

        return $body;
    }
}
