<?php

namespace App\Enums;

enum TasaItbis: string
{
    case DIECIOCHO = '18';
    case DIECISEIS = '16';
    case CERO      = '0';
    case EXENTO    = 'exento';

    public function porcentaje(): float
    {
        return match ($this) {
            self::DIECIOCHO => 18.0,
            self::DIECISEIS => 16.0,
            self::CERO      => 0.0,
            self::EXENTO    => 0.0,
        };
    }

    public function esGravado(): bool
    {
        return match ($this) {
            self::DIECIOCHO, self::DIECISEIS => true,
            default                           => false,
        };
    }
}
