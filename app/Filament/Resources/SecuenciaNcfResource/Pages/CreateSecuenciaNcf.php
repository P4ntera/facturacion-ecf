<?php

namespace App\Filament\Resources\SecuenciaNcfResource\Pages;

use App\Enums\TipoComprobante;
use App\Exceptions\RangoNcfSolapadoException;
use App\Filament\Resources\SecuenciaNcfResource;
use App\Services\SecuenciaNcfService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateSecuenciaNcf extends CreateRecord
{
    protected static string $resource = SecuenciaNcfResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['secuencia_actual'] = $data['secuencia_desde'];

        $servicio = app(SecuenciaNcfService::class);
        $tipo = TipoComprobante::from($data['tipo_comprobante']);

        // Revalidación de negocio (defensa en profundidad además de la regla del formulario):
        // el nuevo rango nunca puede solaparse con uno existente del mismo tipo/prefijo.
        try {
            $servicio->validarSinSolapamiento(
                $tipo,
                $data['prefijo'],
                (int) $data['secuencia_desde'],
                (int) $data['secuencia_hasta'],
            );
        } catch (RangoNcfSolapadoException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }

        // Encolado automático: si ya hay un rango activo para este tipo de comprobante, el nuevo
        // rango se guarda inactivo sin importar lo que haya elegido el usuario en el toggle;
        // se activará solo cuando el activo actual se agote (ver SecuenciaNcfService::siguiente()).
        if (($data['activa'] ?? false) && $servicio->existeRangoActivo($tipo)) {
            $data['activa'] = false;
        }

        return $data;
    }
}
