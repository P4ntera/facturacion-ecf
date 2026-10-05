<?php

declare(strict_types=1);

namespace App\Filament\Resources\AreaRestauranteResource\Pages;

use App\Filament\Resources\AreaRestauranteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAreasRestaurante extends ListRecords
{
    protected static string $resource = AreaRestauranteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
