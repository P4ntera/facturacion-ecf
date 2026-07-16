<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\Reporte607Exporter;
use App\Models\Venta;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * Formato 607 (Envío de Ventas de Bienes y Servicios) de la DGII. La regla fiscal de qué se
 * incluye/excluye (ANULADAs fuera, van al 608) vive en ReporteService::reporte607Query(); esta
 * página solo la muestra con filtro de período y exporta lo que está en pantalla.
 */
class ReporteFiscal607 extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Fiscal 607';

    protected static ?string $title = 'Formato 607 — Envío de ventas';

    protected static ?string $slug = 'reportes/fiscal-607';

    public function table(Table $table): Table
    {
        $servicio = app(ReporteService::class);

        return $table
            ->query(fn () => $servicio->reporte607Query($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                TextColumn::make('ncf')
                    ->label('NCF'),

                TextColumn::make('ncf_modifica')
                    ->label('NCF modificado')
                    ->placeholder('—'),

                TextColumn::make('rnc_cedula')
                    ->label('RNC/Cédula')
                    ->getStateUsing(fn (Venta $record) => $servicio->rncCedula607($record->cliente->tipo_documento, $record->cliente->documento))
                    ->placeholder('—'),

                TextColumn::make('tipo_identificacion')
                    ->label('Tipo identificación')
                    ->getStateUsing(fn (Venta $record) => $servicio->etiquetaTipoIdentificacion607(
                        $servicio->tipoIdentificacion607($record->cliente->tipo_documento),
                    )),

                TextColumn::make('tipo_ingreso')
                    ->label('Tipo de ingreso')
                    ->getStateUsing(fn () => ReporteService::TIPO_INGRESO_DEFECTO),

                TextColumn::make('subtotal')
                    ->label('Monto facturado')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('DOP')),

                TextColumn::make('total_itbis')
                    ->label('ITBIS facturado')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('DOP')),

                TextColumn::make('total')
                    ->label('Monto total')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('DOP')),
            ])
            ->filters([
                Filter::make('rango')
                    ->schema([
                        DatePicker::make('desde')
                            ->label('Desde')
                            ->default(fn () => now()->startOfMonth()->toDateString()),
                        DatePicker::make('hasta')
                            ->label('Hasta')
                            ->default(fn () => now()->endOfMonth()->toDateString()),
                    ]),
            ])
            ->defaultSort('fecha');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.fiscal-607.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return Reporte607Exporter::class;
    }
}
