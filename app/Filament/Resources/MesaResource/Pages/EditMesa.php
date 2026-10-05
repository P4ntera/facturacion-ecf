<?php

declare(strict_types=1);

namespace App\Filament\Resources\MesaResource\Pages;

use App\Filament\Resources\MesaResource;
use Filament\Resources\Pages\EditRecord;

class EditMesa extends EditRecord
{
    protected static string $resource = MesaResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
