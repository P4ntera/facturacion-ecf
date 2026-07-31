<?php

namespace App\Filament\Resources\DescuentoResource\Pages;

use App\Filament\Resources\DescuentoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDescuentos extends ListRecords
{
    protected static string $resource = DescuentoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
