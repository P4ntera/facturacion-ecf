<?php

declare(strict_types=1);

namespace App\Filament\Resources\TicketLavanderiaResource\Pages;

use App\Enums\FormaPago;
use App\Filament\Resources\TicketLavanderiaResource;
use App\Services\TicketLavanderiaService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateTicketLavanderia extends CreateRecord
{
    protected static string $resource = TicketLavanderiaResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $empresa = Filament::getTenant();

        // El toggle pagar_ahora es dehydrated(false), se lee del estado del formulario.
        $pagarAhora = (bool) ($this->form->getRawState()['pagar_ahora'] ?? false);

        if ($pagarAhora) {
            $datosVenta = $data['datos_venta'] ?? [];
            $formaPago = FormaPago::tryFrom($datosVenta['forma_pago'] ?? '') ?? FormaPago::EFECTIVO;

            $data['pagar_ahora'] = true;
            $data['datos_venta'] = [
                'forma_pago' => $formaPago,
                'user_id' => auth()->id(),
            ];

            // Para efectivo: registrar montos y vuelto.
            if ($formaPago === FormaPago::EFECTIVO) {
                $montoRecibido = (float) ($datosVenta['monto_recibido'] ?? 0);
                $data['datos_venta']['monto_efectivo'] = $montoRecibido > 0 ? $montoRecibido : null;
                $data['datos_venta']['vuelto'] = (float) ($datosVenta['vuelto'] ?? 0) ?: null;
            }
        }

        // Limpiar datos_venta del array si no se paga ahora (evitar que llegue basura al model).
        if (! $pagarAhora) {
            unset($data['datos_venta']);
        }

        return app(TicketLavanderiaService::class)->crear(
            empresa: $empresa,
            recepcionista: auth()->user(),
            datos: $data,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
