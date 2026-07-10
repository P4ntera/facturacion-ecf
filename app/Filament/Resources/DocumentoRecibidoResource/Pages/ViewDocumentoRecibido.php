<?php

namespace App\Filament\Resources\DocumentoRecibidoResource\Pages;

use App\Filament\Resources\DocumentoRecibidoResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDocumentoRecibido extends ViewRecord
{
    protected static string $resource = DocumentoRecibidoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
