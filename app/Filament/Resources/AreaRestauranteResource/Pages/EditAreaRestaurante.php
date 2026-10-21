<?php

declare(strict_types=1);

namespace App\Filament\Resources\AreaRestauranteResource\Pages;

use App\Filament\Resources\AreaRestauranteResource;
use Filament\Resources\Pages\EditRecord;

class EditAreaRestaurante extends EditRecord
{
    protected static string $resource = AreaRestauranteResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
