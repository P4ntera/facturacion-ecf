<?php

namespace App\Filament\Resources\SecuenciaNcfResource\Pages;

use App\Filament\Resources\SecuenciaNcfResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSecuenciasNcf extends ListRecords
{
    protected static string $resource = SecuenciaNcfResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
