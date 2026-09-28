<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\Reporte608Exporter;
use App\Models\Venta;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * Formato 608 (Comprobantes Fiscales Anulados) de la DGII. Solo comprobantes físicos (tipo B)
 * anulados; los e-CF se anulan vía Nota de Crédito E34, no a través del 608.
 * Filtra por fecha de anulación (anulada_en), no por fecha de emisión.
 */
class ReporteFiscal608 extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Fiscal 608';

    protected static ?int $navigationSort = 56;

    protected static ?string $title = 'Formato 608 — Comprobantes fiscales anulados';

    protected static ?string $slug = 'reportes/fiscal-608';

    public function table(Table $table): Table
    {
        $servicio = app(ReporteService::class);

        return $table
            ->query(fn () => $servicio->reporte608Query($this->rangoDesde(), $this->rangoHasta()))
            ->columns([
                TextColumn::make('ncf')
                    ->label('NCF anulado'),

                TextColumn::make('tipo_comprobante')
                    ->label('Tipo comprobante')
                    ->formatStateUsing(fn ($state) => $state?->etiqueta() ?? '—'),

                TextColumn::make('tipo_anulacion_608')
                    ->label('Tipo anulación')
                    ->formatStateUsing(fn (?string $state) => $state
                        ? ($state . ' - ' . (ReporteService::TIPO_ANULACION_608[$state] ?? $state))
                        : '—'),

                TextColumn::make('anulada_en')
                    ->label('Fecha anulación')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                TextColumn::make('motivo_anulacion')
                    ->label('Motivo')
                    ->limit(40),
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
            ->defaultSort('anulada_en');
    }

    protected function getHeaderActions(): array
    {
        $puedeExportar = auth()->user()?->can('reportes.exportar') ?? false;

        return [
            ExportAction::make()
                ->label('Exportar Excel/CSV')
                ->exporter($this->exporterClass())
                ->visible($puedeExportar),

            Action::make('exportarTxt608')
                ->label('Exportar TXT (DGII)')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('success')
                ->visible($puedeExportar)
                ->action(function () {
                    $empresa = Filament::getTenant();
                    $servicio = app(ReporteService::class);
                    $contenido = $servicio->exportar608Txt(
                        $empresa->rnc,
                        $this->rangoDesde(),
                        $this->rangoHasta(),
                        $empresa->id,
                    );

                    $periodo = $this->rangoDesde()->format('Ym');
                    $filename = "608_{$empresa->rnc}_{$periodo}.txt";

                    return response()->streamDownload(
                        fn () => print($contenido),
                        $filename,
                        ['Content-Type' => 'text/plain'],
                    );
                }),
        ];
    }

    /**
     * No hay PDF del 608 — el TXT es lo que la DGII requiere.
     */
    protected function pdfRouteName(): string
    {
        return '';
    }

    protected function exporterClass(): string
    {
        return Reporte608Exporter::class;
    }
}
