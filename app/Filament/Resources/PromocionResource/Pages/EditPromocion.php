<?php

namespace App\Filament\Resources\PromocionResource\Pages;

use App\Filament\Resources\PromocionResource;
use Filament\Resources\Pages\EditRecord;

class EditPromocion extends EditRecord
{
    protected static string $resource = PromocionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
