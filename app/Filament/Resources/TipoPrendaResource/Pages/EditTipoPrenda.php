<?php

declare(strict_types=1);

namespace App\Filament\Resources\TipoPrendaResource\Pages;

use App\Filament\Resources\TipoPrendaResource;
use Filament\Resources\Pages\EditRecord;

class EditTipoPrenda extends EditRecord
{
    protected static string $resource = TipoPrendaResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
