<?php

namespace App\Enums;

enum TipoPago: int
{
    case CONTADO = 1;
    case CREDITO = 2;

    public function etiqueta(): string
    {
        return match ($this) {
            self::CONTADO => 'Contado',
            self::CREDITO => 'Crédito',
        };
    }
}
