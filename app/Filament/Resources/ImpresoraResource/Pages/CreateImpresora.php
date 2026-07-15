<?php

namespace App\Filament\Resources\ImpresoraResource\Pages;

use App\Exceptions\ImpresoraInvalidaException;
use App\Filament\Resources\ImpresoraResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateImpresora extends CreateRecord
{
    protected static string $resource = ImpresoraResource::class;

    // Defensa en profundidad además de la validación reactiva del formulario: las reglas de
    // conexión también las exige ImpresoraObserver::saving(), sin importar cómo se cree el registro.
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (ImpresoraInvalidaException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
