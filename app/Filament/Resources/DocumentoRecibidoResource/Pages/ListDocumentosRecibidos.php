<?php

namespace App\Filament\Resources\DocumentoRecibidoResource\Pages;

use App\Filament\Resources\DocumentoRecibidoResource;
use Filament\Resources\Pages\ListRecords;

class ListDocumentosRecibidos extends ListRecords
{
    protected static string $resource = DocumentoRecibidoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
