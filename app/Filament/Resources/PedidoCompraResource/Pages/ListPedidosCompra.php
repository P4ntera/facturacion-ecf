<?php

namespace App\Filament\Resources\PedidoCompraResource\Pages;

use App\Filament\Resources\PedidoCompraResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPedidosCompra extends ListRecords
{
    protected static string $resource = PedidoCompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo pedido'),
        ];
    }
}
