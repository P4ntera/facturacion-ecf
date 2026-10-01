<?php

declare(strict_types=1);

namespace App\Filament\Resources\CotizacionResource\Pages;

use App\Enums\EstadoCotizacion;
use App\Enums\FormaPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Filament\Resources\CotizacionResource;
use App\Models\Cotizacion;
use App\Services\CotizacionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ViewCotizacion extends ViewRecord
{
    protected static string $resource = CotizacionResource::class;

    protected function getHeaderActions(): array
    {
        $user = auth()->user();

        return [
            EditAction::make()
                ->visible(fn (Cotizacion $record) => $record->estado->puedeEditar() && $user->can('cotizaciones.editar')),

            Action::make('enviar')
                ->label('Marcar como Enviada')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('info')
                ->visible(fn (Cotizacion $record) => $record->estado === EstadoCotizacion::BORRADOR)
                ->action(function (Cotizacion $record) {
                    $record->update(['estado' => EstadoCotizacion::ENVIADA]);
                    Notification::make()->title('Cotización marcada como enviada.')->success()->send();
                }),

            Action::make('aprobar')
                ->label('Aprobar')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (Cotizacion $record) => in_array($record->estado, [EstadoCotizacion::BORRADOR, EstadoCotizacion::ENVIADA]) && $user->can('cotizaciones.aprobar'))
                ->action(function (Cotizacion $record) {
                    $record->update([
                        'estado' => EstadoCotizacion::APROBADA,
                        'aprobado_por' => auth()->id(),
                        'aprobado_en' => now(),
                    ]);
                    Notification::make()->title('Cotización aprobada.')->success()->send();
                }),

            Action::make('rechazar')
                ->label('Rechazar')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (Cotizacion $record) => in_array($record->estado, [EstadoCotizacion::BORRADOR, EstadoCotizacion::ENVIADA, EstadoCotizacion::APROBADA]))
                ->requiresConfirmation()
                ->modalHeading('¿Rechazar esta cotización?')
                ->action(function (Cotizacion $record) {
                    $record->update(['estado' => EstadoCotizacion::RECHAZADA]);
                    Notification::make()->title('Cotización rechazada.')->warning()->send();
                }),

            Action::make('facturar')
                ->label('Facturar')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('success')
                ->visible(fn (Cotizacion $record) => $record->puedeConvertirse() && $user->can('cotizaciones.facturar'))
                ->form([
                    Select::make('tipo_comprobante')
                        ->label('Tipo de Comprobante')
                        ->options(
                            collect(TipoComprobante::cases())
                                ->filter(fn (TipoComprobante $t) => $t->esDeVenta())
                                ->mapWithKeys(fn (TipoComprobante $t) => [$t->value => $t->etiqueta()])
                                ->all()
                        )
                        ->required(),

                    Select::make('forma_pago')
                        ->label('Forma de Pago')
                        ->options(collect(FormaPago::cases())
                            ->mapWithKeys(fn (FormaPago $f) => [$f->value => $f->etiqueta()])
                            ->all())
                        ->default(FormaPago::EFECTIVO->value)
                        ->required(),

                    Select::make('tipo_pago')
                        ->label('Tipo de Pago')
                        ->options(collect(TipoPago::cases())
                            ->mapWithKeys(fn (TipoPago $t) => [$t->value => $t->etiqueta()])
                            ->all())
                        ->default(TipoPago::CONTADO->value)
                        ->required(),
                ])
                ->action(function (Cotizacion $record, array $data) {
                    try {
                        $tipoComprobante = TipoComprobante::from($data['tipo_comprobante']);
                        $formaPago = FormaPago::from($data['forma_pago']);
                        $tipoPago = TipoPago::from((int) $data['tipo_pago']);

                        $venta = app(CotizacionService::class)->convertirAVenta(
                            $record,
                            Filament::getTenant(),
                            [
                                'tipo_comprobante' => $tipoComprobante,
                                'forma_pago' => $formaPago,
                                'tipo_pago' => $tipoPago,
                            ],
                        );

                        Notification::make()
                            ->title("Venta creada: {$venta->ncf}")
                            ->success()
                            ->send();
                    } catch (RuntimeException|\App\Exceptions\VentaInvalidaException|\App\Exceptions\StockInsuficienteException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('duplicar')
                ->label('Duplicar')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->visible(fn () => $user->can('cotizaciones.crear'))
                ->requiresConfirmation()
                ->modalHeading('¿Duplicar esta cotización?')
                ->modalDescription('Se creará una nueva cotización en borrador con los mismos productos y precios.')
                ->action(function (Cotizacion $record) {
                    try {
                        $nueva = app(CotizacionService::class)->duplicar($record, Filament::getTenant());
                        Notification::make()->title("Cotización {$nueva->numero} creada.")->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('exportarPdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn () => $user->can('cotizaciones.exportar'))
                ->url(fn (Cotizacion $record) => route('cotizaciones.pdf', $record), shouldOpenInNewTab: true),
        ];
    }
}
