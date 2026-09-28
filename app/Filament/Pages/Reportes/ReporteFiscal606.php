<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\Reporte606Exporter;
use App\Models\Compra;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * Formato 606 (Envío de Compras de Bienes y Servicios) de la DGII. La regla fiscal de qué se
 * incluye/excluye vive en ReporteService::reporte606Query(); esta página solo la muestra con
 * filtro de período y exporta lo que está en pantalla.
 */
class ReporteFiscal606 extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Fiscal 606';

    protected static ?int $navigationSort = 54;

    protected static ?string $title = 'Formato 606 — Compras de bienes y servicios';

    protected static ?string $slug = 'reportes/fiscal-606';

    public function table(Table $table): Table
    {
        $servicio = app(ReporteService::class);

        return $table
            ->query(fn () => $servicio->reporte606Query($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                TextColumn::make('ncf')
                    ->label('NCF proveedor'),

                TextColumn::make('proveedor.rnc')
                    ->label('RNC/Cédula')
                    ->placeholder('—'),

                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->limit(30),

                TextColumn::make('tipo_bienes_servicios_606')
                    ->label('Tipo bienes/serv.')
                    ->formatStateUsing(fn (?string $state) => ReporteService::TIPO_BIENES_SERVICIOS_606[$state] ?? $state),

                TextColumn::make('subtotal')
                    ->label('Monto facturado')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('DOP')),

                TextColumn::make('itbis')
                    ->label('ITBIS facturado')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('DOP')),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('DOP')),

                TextColumn::make('forma_pago_606')
                    ->label('Forma pago')
                    ->formatStateUsing(fn (?string $state) => ReporteService::FORMA_PAGO_606[$state] ?? $state),
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

    protected function getHeaderActions(): array
    {
        $puedeExportar = auth()->user()?->can('reportes.exportar') ?? false;

        return [
            ExportAction::make()
                ->label('Exportar Excel/CSV')
                ->exporter($this->exporterClass())
                ->visible($puedeExportar),

            Action::make('exportarTxt606')
                ->label('Exportar TXT (DGII)')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('success')
                ->visible($puedeExportar)
                ->action(function () {
                    $empresa = Filament::getTenant();
                    $servicio = app(ReporteService::class);
                    $contenido = $servicio->exportar606Txt(
                        $empresa->rnc,
                        $this->rangoDesde(),
                        $this->rangoHasta(),
                        $empresa->id,
                    );

                    $periodo = $this->rangoDesde()->format('Ym');
                    $filename = "606_{$empresa->rnc}_{$periodo}.txt";

                    return response()->streamDownload(
                        fn () => print($contenido),
                        $filename,
                        ['Content-Type' => 'text/plain'],
                    );
                }),
        ];
    }

    /**
     * No hay PDF del 606 por ahora — el TXT es lo que la DGII requiere.
     * El botón PDF está excluido del getHeaderActions() de esta página.
     */
    protected function pdfRouteName(): string
    {
        return '';
    }

    protected function exporterClass(): string
    {
        return Reporte606Exporter::class;
    }
}
