<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

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
