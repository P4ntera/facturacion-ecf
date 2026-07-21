<?php

namespace App\Filament\Resources\ArqueoCajaResource\Pages;

use App\Filament\Resources\ArqueoCajaResource;
use Filament\Resources\Pages\ViewRecord;

class ViewArqueoCaja extends ViewRecord
{
    protected static string $resource = ArqueoCajaResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
