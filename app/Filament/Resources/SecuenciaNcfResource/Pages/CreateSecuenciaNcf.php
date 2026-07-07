<?php

namespace App\Filament\Resources\SecuenciaNcfResource\Pages;

use App\Filament\Resources\SecuenciaNcfResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSecuenciaNcf extends CreateRecord
{
    protected static string $resource = SecuenciaNcfResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['secuencia_actual'] = $data['secuencia_desde'];

        return $data;
    }
}
