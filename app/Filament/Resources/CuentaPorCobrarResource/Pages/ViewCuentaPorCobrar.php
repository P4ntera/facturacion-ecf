<?php

namespace App\Filament\Resources\CuentaPorCobrarResource\Pages;

use App\Filament\Resources\CuentaPorCobrarResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCuentaPorCobrar extends ViewRecord
{
    protected static string $resource = CuentaPorCobrarResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
