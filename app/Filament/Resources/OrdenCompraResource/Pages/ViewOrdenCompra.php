<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrdenCompraResource\Pages;

use App\Enums\EstadoOrdenCompra;
use App\Filament\Resources\OrdenCompraResource;
use App\Models\DetalleOrdenCompra;
use App\Models\OrdenCompra;
use App\Services\OrdenCompraService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ViewOrdenCompra extends ViewRecord
{
    protected static string $resource = OrdenCompraResource::class;

    protected function getHeaderActions(): array
    {
        $user = auth()->user();

        return [
            EditAction::make()
                ->visible(fn (OrdenCompra $record) => $record->estado->puedeEditar() && $user->can('ordenes_compra.editar')),

            Action::make('aprobar')
                ->label('Aprobar y Enviar')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (OrdenCompra $record) => $record->estado === EstadoOrdenCompra::BORRADOR && $user->can('ordenes_compra.aprobar'))
                ->requiresConfirmation()
                ->modalHeading('¿Aprobar esta orden de compra?')
                ->action(function (OrdenCompra $record) {
                    try {
                        app(OrdenCompraService::class)->aprobar($record);
                        Notification::make()->title('Orden aprobada y enviada.')->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('enviar')
                ->label('Enviar al Proveedor')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('info')
                ->visible(fn (OrdenCompra $record) => $record->estado === EstadoOrdenCompra::BORRADOR && ! $user->can('ordenes_compra.aprobar'))
                ->requiresConfirmation()
                ->action(function (OrdenCompra $record) {
                    try {
                        app(OrdenCompraService::class)->enviar($record);
                        Notification::make()->title('Orden enviada al proveedor.')->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('recibirMercancia')
                ->label('Recibir Mercancía')
                ->icon(Heroicon::OutlinedTruck)
                ->color('success')
                ->visible(fn (OrdenCompra $record) => $record->puedeRecibir() && $user->can('ordenes_compra.recibir'))
                ->form(function (OrdenCompra $record) {
                    $record->load('detalles.producto');

                    $items = $record->detalles
                        ->filter(fn (DetalleOrdenCompra $d) => bccomp($d->cantidadPendiente(), '0', 4) > 0)
                        ->map(fn (DetalleOrdenCompra $d) => [
                            'detalle_orden_compra_id' => $d->id,
                            'producto_nombre' => $d->producto->nombre,
                            'solicitado' => (string) $d->cantidad_solicitada,
                            'recibido' => (string) $d->cantidad_recibida,
                            'pendiente' => $d->cantidadPendiente(),
                            'cantidad_recibida' => $d->cantidadPendiente(),
                        ])
                        ->values()
                        ->all();

                    return [
                        Textarea::make('notas')
                            ->label('Observaciones')
                            ->rows(2),

                        Repeater::make('lineas')
                            ->label('Líneas pendientes')
                            ->schema([
                                Hidden::make('detalle_orden_compra_id'),
                                Placeholder::make('producto_nombre')->label('Producto')
                                    ->content(fn ($get) => $get('producto_nombre')),
                                Placeholder::make('solicitado')->label('Solicitado')
                                    ->content(fn ($get) => $get('solicitado')),
                                Placeholder::make('recibido')->label('Ya recibido')
                                    ->content(fn ($get) => $get('recibido')),
                                Placeholder::make('pendiente')->label('Pendiente')
                                    ->content(fn ($get) => $get('pendiente')),
                                TextInput::make('cantidad_recibida')
                                    ->label('Recibir ahora')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->columns(6)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->default($items),
                    ];
                })
                ->action(function (OrdenCompra $record, array $data) {
                    try {
                        app(OrdenCompraService::class)->registrarRecepcion(
                            $record,
                            $data['lineas'] ?? [],
                            Filament::getTenant(),
                            $data['notas'] ?? null,
                        );

                        Notification::make()->title('Recepción registrada exitosamente.')->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('exportarPdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn () => $user->can('ordenes_compra.exportar'))
                ->url(fn (OrdenCompra $record) => route('ordenes-compra.pdf', $record), shouldOpenInNewTab: true),

            Action::make('cancelar')
                ->label('Cancelar Orden')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (OrdenCompra $record) => $record->estado->puedeCancelar() && $user->can('ordenes_compra.cancelar'))
                ->requiresConfirmation()
                ->modalHeading('¿Cancelar esta orden de compra?')
                ->modalDescription('Esta acción no se puede revertir.')
                ->action(function (OrdenCompra $record) {
                    try {
                        app(OrdenCompraService::class)->cancelar($record);
                        Notification::make()->title('Orden cancelada.')->warning()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
