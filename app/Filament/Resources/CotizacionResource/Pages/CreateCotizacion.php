<?php

declare(strict_types=1);

namespace App\Filament\Resources\CotizacionResource\Pages;

use App\Filament\Resources\CotizacionResource;
use App\Services\CotizacionService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateCotizacion extends CreateRecord
{
    protected static string $resource = CotizacionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Cotización creada exitosamente';
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CotizacionService::class)->crear(
                [
                    'cliente_id' => $data['cliente_id'] ?? null,
                    'fecha' => $data['fecha'],
                    'dias_vigencia' => $data['dias_vigencia'] ?? 15,
                    'condiciones_pago' => $data['condiciones_pago'] ?? null,
                    'notas' => $data['notas'] ?? null,
                    'lineas' => $data['lineas'] ?? [],
                ],
                auth()->id(),
                Filament::getTenant(),
            );
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
