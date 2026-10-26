<?php

declare(strict_types=1);

namespace App\Filament\Resources\TicketLavanderiaResource\Pages;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Filament\Resources\TicketLavanderiaResource;
use App\Services\TicketLavanderiaService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTicketLavanderia extends ViewRecord
{
    protected static string $resource = TicketLavanderiaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('avanzar_estado')
                ->label(fn () => match ($this->record->estado) {
                    EstadoTicketLavanderia::RECIBIDO => 'Iniciar Proceso',
                    EstadoTicketLavanderia::EN_PROCESO => 'Marcar como Listo',
                    default => 'Avanzar',
                })
                ->icon('heroicon-o-arrow-right')
                ->color(fn () => match ($this->record->estado) {
                    EstadoTicketLavanderia::RECIBIDO => 'warning',
                    EstadoTicketLavanderia::EN_PROCESO => 'success',
                    default => 'gray',
                })
                ->visible(fn () => in_array($this->record->estado, [
                    EstadoTicketLavanderia::RECIBIDO,
                    EstadoTicketLavanderia::EN_PROCESO,
                ]))
                ->requiresConfirmation()
                ->action(function () {
                    app(TicketLavanderiaService::class)->avanzarEstado($this->record);
                    Notification::make()->title('Estado actualizado')->success()->send();
                    $this->refreshFormData(['estado']);
                }),

            Action::make('entregar')
                ->label('Entregar al Cliente')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->estado === EstadoTicketLavanderia::LISTO)
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->estado_pago === EstadoPagoTicket::PENDIENTE
                    ? 'Se generará la factura y se marcará como entregado.'
                    : 'Se marcará como entregado.')
                ->action(function () {
                    app(TicketLavanderiaService::class)->entregar(
                        $this->record,
                        Filament::getTenant(),
                    );
                    Notification::make()->title('Ticket entregado')->success()->send();
                    $this->refreshFormData(['estado', 'estado_pago', 'entregado_en']);
                }),

            Action::make('comprobante')
                ->label('Imprimir Comprobante')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('lavanderia.ticket.recibo', $this->record), shouldOpenInNewTab: true),

            Action::make('cancelar')
                ->label('Cancelar Ticket')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => ! in_array($this->record->estado, [
                    EstadoTicketLavanderia::ENTREGADO,
                    EstadoTicketLavanderia::CANCELADO,
                ]))
                ->requiresConfirmation()
                ->modalHeading('¿Cancelar este ticket?')
                ->modalDescription('Esta acción no se puede deshacer.')
                ->action(function () {
                    app(TicketLavanderiaService::class)->cancelar($this->record);
                    Notification::make()->title('Ticket cancelado')->warning()->send();
                    $this->refreshFormData(['estado']);
                }),
        ];
    }
}
