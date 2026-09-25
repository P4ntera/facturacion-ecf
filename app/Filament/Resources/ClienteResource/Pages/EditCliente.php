<?php

namespace App\Filament\Resources\ClienteResource\Pages;

use App\Filament\Resources\ClienteResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditCliente extends EditRecord
{
    protected static string $resource = ClienteResource::class;

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
}
