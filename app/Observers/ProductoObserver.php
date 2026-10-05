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

        // En negativo (se vendió sin stock): mismo permiso que el stock bajo, pero un aviso más
        // fuerte, porque el inventario del sistema no cuadra con el físico hasta que se corrija.
        $notificacion = (float) $producto->stock < 0
            ? Notification::make()
                ->title("Stock en negativo: {$producto->nombre}")
                ->body("Se vendió sin stock en el sistema. Stock actual: {$producto->stock}. Registra la compra que falta o ajusta tras contar.")
                ->danger()
            : Notification::make()
                ->title("Stock bajo del mínimo: {$producto->nombre}")
                ->body("Stock actual: {$producto->stock} (mínimo: {$producto->stock_minimo}).")
                ->warning();

        app(NotificacionService::class)->enviar(TipoNotificacion::STOCK_BAJO, $producto->empresa, $notificacion);
    }
}
