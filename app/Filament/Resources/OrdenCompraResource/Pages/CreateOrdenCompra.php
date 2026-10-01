<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrdenCompraResource\Pages;

use App\Filament\Resources\OrdenCompraResource;
use App\Services\OrdenCompraService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Filament\Support\Exceptions\Halt;
use RuntimeException;

class CreateOrdenCompra extends CreateRecord
{
    protected static string $resource = OrdenCompraResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Orden de compra creada exitosamente';
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(OrdenCompraService::class)->crear(
                [
                    'proveedor_id' => $data['proveedor_id'],
                    'fecha' => $data['fecha'],
                    'fecha_esperada' => $data['fecha_esperada'] ?? null,
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
