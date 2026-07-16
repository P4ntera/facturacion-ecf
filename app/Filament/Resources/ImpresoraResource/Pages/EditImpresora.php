<?php

namespace App\Filament\Resources\ImpresoraResource\Pages;

use App\Exceptions\ImpresoraInvalidaException;
use App\Filament\Resources\ImpresoraResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditImpresora extends EditRecord
{
    protected static string $resource = ImpresoraResource::class;

    // Sin borrado físico: se da de baja con el toggle "activa" (queda como historial).
    protected function getHeaderActions(): array
    {
        return [];
    }

    // Defensa en profundidad además de la validación reactiva del formulario: las reglas de
    // conexión también las exige ImpresoraObserver::saving(), sin importar cómo se edite el registro.
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (ImpresoraInvalidaException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
