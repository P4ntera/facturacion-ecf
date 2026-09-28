<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TipoNotificacion;
use App\Models\Producto;
use App\Services\NotificacionService;
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

        app(NotificacionService::class)->enviar(
            TipoNotificacion::STOCK_BAJO,
            $producto->empresa,
            Notification::make()
                ->title("Stock bajo del mínimo: {$producto->nombre}")
                ->body("Stock actual: {$producto->stock} (mínimo: {$producto->stock_minimo}).")
                ->warning(),
        );
    }
}
