<?php

namespace App\Filament\Resources\PedidoCompraResource\Pages;

use App\Filament\Resources\PedidoCompraResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPedidoCompra extends ViewRecord
{
    protected static string $resource = PedidoCompraResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
