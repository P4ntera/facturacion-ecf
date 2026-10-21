<?php

namespace App\Enums;

enum FormaPago: string
{
    case EFECTIVO      = 'efectivo';
    case TARJETA       = 'tarjeta';
    case TRANSFERENCIA = 'transferencia';
    case MIXTO         = 'mixto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EFECTIVO      => 'Efectivo',
            self::TARJETA       => 'Tarjeta',
            self::TRANSFERENCIA => 'Transferencia',
            self::MIXTO         => 'Mixto (Efectivo + Tarjeta)',
        };
    }

    /** true si esta forma de pago incluye componente en efectivo (para cálculo de vuelto). */
    public function incluyeEfectivo(): bool
    {
        return match ($this) {
            self::EFECTIVO, self::MIXTO => true,
            default => false,
        };
    }
}
