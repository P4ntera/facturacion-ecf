<?php

namespace App\Filament\Resources\DescuentoResource\Pages;

use App\Filament\Resources\DescuentoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDescuento extends CreateRecord
{
    protected static string $resource = DescuentoResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
