<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Producto;
use App\Models\User;
use Filament\Notifications\Notification;

class ProductoObserver
{
    public function updated(Producto $producto): void
    {
        if (! $producto->wasChanged('stock')) {
            return;
        }

        if (! $producto->controla_stock || (float) $producto->stock > (float) $producto->stock_minimo) {
            return;
        }

        $destinatarios = User::permission('gestionar_inventario')->get();

        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::make()
            ->title("Stock bajo del mínimo: {$producto->nombre}")
            ->body("Stock actual: {$producto->stock} (mínimo: {$producto->stock_minimo}).")
            ->warning()
            ->sendToDatabase($destinatarios);
    }
}
