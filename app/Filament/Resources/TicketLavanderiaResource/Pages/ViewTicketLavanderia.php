<?php

declare(strict_types=1);

namespace App\Filament\Resources\TicketLavanderiaResource\Pages;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Enums\FormaPago;
use App\Filament\Resources\TicketLavanderiaResource;
use App\Services\TicketLavanderiaService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

class ViewTicketLavanderia extends ViewRecord
{
    protected static string $resource = TicketLavanderiaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('avanzar_estado')
                ->label('Marcar como Listo')
                ->icon('heroicon-o-check')
                ->color('success')
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

            Action::make('cobrar')
                ->label('Cobrar')
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->visible(fn () => $this->record->estado_pago === EstadoPagoTicket::PENDIENTE
                    && ! in_array($this->record->estado, [EstadoTicketLavanderia::CANCELADO]))
                ->modalHeading('Cobrar Ticket')
                ->modalDescription(fn () => 'Total a cobrar: RD$ ' . number_format((float) $this->record->total, 2))
                ->form([
                    Select::make('forma_pago')
                        ->label('Forma de Pago')
                        ->options(
                            collect(FormaPago::cases())
                                ->reject(fn (FormaPago $fp) => $fp === FormaPago::MIXTO)
                                ->mapWithKeys(fn (FormaPago $fp) => [$fp->value => $fp->etiqueta()])
                        )
                        ->default(FormaPago::EFECTIVO->value)
                        ->required()
                        ->live(),

                    TextInput::make('monto_recibido')
                        ->label('Monto Recibido')
                        ->numeric()
                        ->prefix('RD$')
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => ($get('forma_pago') ?? FormaPago::EFECTIVO->value) === FormaPago::EFECTIVO->value)
                        ->live(debounce: 500)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $recibido = (float) ($get('monto_recibido') ?? 0);
                            $total = (float) $this->record->total;
                            $vuelto = max(0, round($recibido - $total, 2));
                            $set('vuelto', $vuelto > 0 ? $vuelto : 0);
                        }),

                    Placeholder::make('vuelto_display')
                        ->label('Devuelta')
                        ->visible(fn (Get $get): bool => ($get('forma_pago') ?? FormaPago::EFECTIVO->value) === FormaPago::EFECTIVO->value)
                        ->content(function (Get $get): HtmlString {
                            $vuelto = (float) ($get('vuelto') ?? 0);

                            return new HtmlString(
                                '<span class="text-2xl font-bold text-success-600 dark:text-success-400">RD$ ' . number_format($vuelto, 2) . '</span>'
                            );
                        }),

                    TextInput::make('vuelto')
                        ->hidden()
                        ->default(0),
                ])
                ->action(function (array $data) {
                    $formaPago = FormaPago::tryFrom($data['forma_pago'] ?? '') ?? FormaPago::EFECTIVO;

                    $datosVenta = [
                        'forma_pago' => $formaPago,
                        'user_id' => auth()->id(),
                    ];

                    if ($formaPago === FormaPago::EFECTIVO) {
                        $montoRecibido = (float) ($data['monto_recibido'] ?? 0);
                        $datosVenta['monto_efectivo'] = $montoRecibido > 0 ? $montoRecibido : null;
                        $datosVenta['vuelto'] = (float) ($data['vuelto'] ?? 0) ?: null;
                    }

                    app(TicketLavanderiaService::class)->cobrar(
                        $this->record,
                        Filament::getTenant(),
                        $datosVenta,
                    );

                    Notification::make()->title('Ticket cobrado')->success()->send();
                    $this->refreshFormData(['estado_pago', 'venta_id']);
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
