<?php

namespace App\Filament\Resources\MovimientoInventarioResource\Pages;

use App\Filament\Resources\MovimientoInventarioResource;
use Filament\Resources\Pages\ListRecords;

class ListMovimientoInventarios extends ListRecords
{
    protected static string $resource = MovimientoInventarioResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
