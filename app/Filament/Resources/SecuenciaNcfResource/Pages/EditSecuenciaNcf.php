<?php

namespace App\Filament\Resources\SecuenciaNcfResource\Pages;

use App\Filament\Resources\SecuenciaNcfResource;
use Filament\Resources\Pages\EditRecord;

class EditSecuenciaNcf extends EditRecord
{
    protected static string $resource = SecuenciaNcfResource::class;

    // Sin borrado físico: el rango se da de baja con el toggle "activa" (queda como historial).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
