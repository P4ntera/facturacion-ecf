<?php

namespace App\Filament\Resources\VentaResource\Pages;

use App\Filament\Pages\Caja;
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
                ->label('Ir a Caja')
                ->icon('heroicon-o-shopping-cart')
                ->url(Caja::getUrl())
                ->visible(fn (): bool => auth()->user()?->can('pos.acceder') ?? false),
        ];
    }
}
