<?php

namespace App\Filament\Resources\ArqueoCajaResource\Pages;

use App\Filament\Resources\ArqueoCajaResource;
use Filament\Resources\Pages\ListRecords;

class ListArqueosCaja extends ListRecords
{
    protected static string $resource = ArqueoCajaResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
