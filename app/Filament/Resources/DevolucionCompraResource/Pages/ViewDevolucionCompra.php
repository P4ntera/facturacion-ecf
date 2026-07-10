<?php

namespace App\Filament\Resources\DevolucionCompraResource\Pages;

use App\Filament\Resources\DevolucionCompraResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDevolucionCompra extends ViewRecord
{
    protected static string $resource = DevolucionCompraResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
