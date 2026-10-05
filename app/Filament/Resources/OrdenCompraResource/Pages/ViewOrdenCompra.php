<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrdenCompraResource\Pages;

use App\Enums\EstadoOrdenCompra;
use App\Filament\Resources\CompraResource;
use App\Filament\Resources\OrdenCompraResource;
use App\Models\OrdenCompra;
use App\Services\OrdenCompraService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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

            // Recibir mercancía ES registrar la compra: abre el formulario de compra con lo
            // pendiente de la orden. La compra mueve stock, costo, CxP y 606, y anota lo recibido
            // en la orden (ver CompraService / OrdenCompraService::registrarRecepcionDeCompra).
            Action::make('recibirMercancia')
                ->label('Recibir Mercancía')
                ->icon(Heroicon::OutlinedTruck)
                ->color('success')
                ->visible(fn (OrdenCompra $record) => $record->puedeRecibir()
                    && $user->can('ordenes_compra.recibir')
                    && $user->can('compras.crear'))
                ->url(fn (OrdenCompra $record) => CompraResource::getUrl('create', ['orden' => $record->id])),

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
