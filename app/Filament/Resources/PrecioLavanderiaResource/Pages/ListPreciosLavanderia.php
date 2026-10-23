<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrecioLavanderiaResource\Pages;

use App\Filament\Resources\PrecioLavanderiaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPreciosLavanderia extends ListRecords
{
    protected static string $resource = PrecioLavanderiaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
