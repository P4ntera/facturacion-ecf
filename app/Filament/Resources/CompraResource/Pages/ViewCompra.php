<?php

namespace App\Filament\Resources\CompraResource\Pages;

use App\Filament\Resources\CompraResource;
use App\Filament\Resources\DevolucionCompraResource;
use App\Models\Compra;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCompra extends ViewRecord
{
    protected static string $resource = CompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrarDevolucion')
                ->label('Registrar devolución')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Compra $record): bool => ! $record->estaAnulada() && (auth()->user()?->can('gestionar_compras') ?? false))
                ->url(fn (Compra $record): string => DevolucionCompraResource::getUrl('create', ['compra_id' => $record->id])),
        ];
    }
}
