<?php

namespace App\Filament\Resources\VentaResource\Pages;

use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\VentaResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListVentas extends ListRecords
{
    protected static string $resource = VentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevaVenta')
                ->label('Ir al Punto de Venta')
                ->icon('heroicon-o-shopping-cart')
                ->url(PuntoDeVenta::getUrl())
                ->visible(fn (): bool => auth()->user()?->can('pos.acceder') ?? false),
        ];
    }
}
