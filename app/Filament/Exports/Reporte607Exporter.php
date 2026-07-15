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
 * TODO(fiscal-607-txt): la DGII exige, para la carga real en la Oficina Virtual, un archivo
 * TXT de 23 columnas separadas por "|" con un layout posicional propio (no este Excel/CSV, que
 * es para revisión interna). Se intentó confirmar el orden y los códigos exactos de esas 23
 * columnas contra el instructivo oficial (dgii.gov.do, "5-InstructivoLlenadoyenvioFomato607.pdf")
 * pero el PDF no permitió una extracción de texto confiable en este entorno (sin herramientas
 * de renderizado de PDF disponibles) más allá de fragmentos sueltos. Los 9 campos que sí se
 * confirmaron con la comunidad de ayuda de la DGII (RNC/Cédula, tipo de identificación 1/2,
 * NCF, NCF modificado, fecha, monto facturado sin ITBIS, ITBIS facturado) están implementados
 * aquí y en ReporteService::reporte607(). Antes de implementar el TXT de carga, hay que
 * descargar la plantilla oficial vigente (Oficina Virtual DGII > Formularios > Formatos de
 * envío de datos) y fijar el layout completo de las 23 columnas.
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
                ->getStateUsing(fn (Venta $record) => $servicio->rncCedula607($record->cliente->tipo_documento, $record->cliente->documento)),
            ExportColumn::make('tipo_identificacion')
                ->label('Tipo identificación')
                ->getStateUsing(fn (Venta $record) => $servicio->tipoIdentificacion607($record->cliente->tipo_documento)),
            ExportColumn::make('tipo_ingreso')
                ->label('Tipo de ingreso')
                ->getStateUsing(fn () => ReporteService::TIPO_INGRESO_DEFECTO),
            ExportColumn::make('subtotal')
                ->label('Monto facturado'),
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
