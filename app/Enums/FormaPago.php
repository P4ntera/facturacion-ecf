<?php

namespace App\Enums;

enum FormaPago: string
{
    case EFECTIVO      = 'efectivo';
    case TARJETA       = 'tarjeta';
    case TRANSFERENCIA = 'transferencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EFECTIVO      => 'Efectivo',
            self::TARJETA       => 'Tarjeta',
            self::TRANSFERENCIA => 'Transferencia',
        };
    }
}
