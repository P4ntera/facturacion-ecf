<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Exporter;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Carbon;
use UnitEnum;

abstract class ReportePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Reportes';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ver_reportes') ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Nombre de la ruta que genera el PDF de este reporte (definida en routes/web.php).
     */
    abstract protected function pdfRouteName(): string;

    /**
     * @return array<string, mixed>
     */
    protected function pdfRouteParams(): array
    {
        return [];
    }

    /**
     * Clase del exportador nativo de Filament (columnas y notificación de este reporte).
     *
     * @return class-string<Exporter>
     */
    abstract protected function exporterClass(): string;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label('Exportar Excel/CSV')
                ->exporter($this->exporterClass()),

            Action::make('exportarPdf')
                ->label('Exportar PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->url(fn (): string => route($this->pdfRouteName(), $this->pdfRouteParams()), shouldOpenInNewTab: true),
        ];
    }

    /**
     * Rango de fechas aplicado actualmente en el filtro "rango" de la tabla: por defecto,
     * el mes en curso. Los subtipos que exportan PDF/Excel deben leer este mismo rango para
     * que el archivo refleje exactamente lo que está filtrado en pantalla.
     */
    protected function rangoDesde(): Carbon
    {
        return Carbon::parse($this->tableFilters['rango']['desde'] ?? now()->startOfMonth()->toDateString())->startOfDay();
    }

    protected function rangoHasta(): Carbon
    {
        return Carbon::parse($this->tableFilters['rango']['hasta'] ?? now()->endOfMonth()->toDateString())->endOfDay();
    }
}
