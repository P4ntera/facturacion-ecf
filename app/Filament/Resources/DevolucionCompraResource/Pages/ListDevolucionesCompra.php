<?php

namespace App\Filament\Resources\DevolucionCompraResource\Pages;

use App\Filament\Resources\DevolucionCompraResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDevolucionesCompra extends ListRecords
{
    protected static string $resource = DevolucionCompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva devolución'),
        ];
    }
}
