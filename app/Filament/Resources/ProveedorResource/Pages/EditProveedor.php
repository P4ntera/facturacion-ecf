<?php

namespace App\Filament\Resources\ProveedorResource\Pages;

use App\Filament\Resources\ProveedorResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditProveedor extends EditRecord
{
    protected static string $resource = ProveedorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleActivo')
                ->label(fn () => $this->record->activo ? 'Desactivar' : 'Activar')
                ->icon(fn () => $this->record->activo ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                ->color(fn () => $this->record->activo ? 'danger' : 'success')
                ->requiresConfirmation()
                ->action(fn () => $this->record->update(['activo' => ! $this->record->activo])),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Proveedor actualizado exitosamente';
    }
}
