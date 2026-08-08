<?php

namespace App\Filament\Resources\CuentaPorPagarResource\Pages;

use App\Filament\Resources\CuentaPorPagarResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCuentaPorPagar extends ViewRecord
{
    protected static string $resource = CuentaPorPagarResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
