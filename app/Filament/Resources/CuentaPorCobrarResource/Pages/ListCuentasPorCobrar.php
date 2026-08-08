<?php

namespace App\Filament\Resources\CuentaPorCobrarResource\Pages;

use App\Filament\Resources\CuentaPorCobrarResource;
use Filament\Resources\Pages\ListRecords;

class ListCuentasPorCobrar extends ListRecords
{
    protected static string $resource = CuentaPorCobrarResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
