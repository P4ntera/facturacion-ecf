<?php

namespace App\Filament\Resources\PromocionResource\Pages;

use App\Filament\Resources\PromocionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromocion extends CreateRecord
{
    protected static string $resource = PromocionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
