<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServicioLavanderiaResource\Pages;

use App\Filament\Resources\ServicioLavanderiaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServicioLavanderia extends CreateRecord
{
    protected static string $resource = ServicioLavanderiaResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
