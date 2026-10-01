<?php

declare(strict_types=1);

namespace App\Filament\Resources\CotizacionResource\Pages;

use App\Filament\Resources\CotizacionResource;
use Filament\Resources\Pages\EditRecord;

class EditCotizacion extends EditRecord
{
    protected static string $resource = CotizacionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
