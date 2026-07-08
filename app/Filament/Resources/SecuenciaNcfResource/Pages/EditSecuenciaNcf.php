<?php

namespace App\Filament\Resources\SecuenciaNcfResource\Pages;

use App\Enums\TipoComprobante;
use App\Exceptions\RangoNcfSolapadoException;
use App\Filament\Resources\SecuenciaNcfResource;
use App\Services\SecuenciaNcfService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditSecuenciaNcf extends EditRecord
{
    protected static string $resource = SecuenciaNcfResource::class;

    // Sin borrado físico: el rango se da de baja con el toggle "activa" (queda como historial).
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Revalidación de negocio (defensa en profundidad además de la regla del formulario):
        // el rango editado nunca puede terminar solapándose con otro existente.
        try {
            app(SecuenciaNcfService::class)->validarSinSolapamiento(
                TipoComprobante::from($data['tipo_comprobante']),
                $data['prefijo'],
                (int) $data['secuencia_desde'],
                (int) $data['secuencia_hasta'],
                ignorarId: $this->record->id,
            );
        } catch (RangoNcfSolapadoException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }

        return $data;
    }
}
