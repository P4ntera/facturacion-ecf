<?php

namespace App\Filament\Resources\ProveedorResource\Pages;

use App\Filament\Resources\ProveedorResource;
<<<<<<< HEAD
use Filament\Actions\DeleteAction;
=======
use Filament\Actions;
>>>>>>> Lamar
use Filament\Resources\Pages\EditRecord;

class EditProveedor extends EditRecord
{
    protected static string $resource = ProveedorResource::class;

    protected function getHeaderActions(): array
    {
<<<<<<< HEAD
        return [DeleteAction::make()];
=======
        return [
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Proveedor actualizado exitosamente';
>>>>>>> Lamar
    }
}
