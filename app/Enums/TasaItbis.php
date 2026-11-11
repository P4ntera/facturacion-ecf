<?php

namespace App\Enums;

enum TasaItbis: string
{
    case DIECIOCHO = '18';
    case DIECISEIS = '16';
    case CERO = '0';
    case EXENTO = 'E';

    public function porcentaje(): float
    {
        return match ($this) {
            self::DIECIOCHO => 18.0,
            self::DIECISEIS => 16.0,
            self::CERO, self::EXENTO => 0.0,
        };
    }

    public function esGravado(): bool
    {
        return match ($this) {
            self::DIECIOCHO, self::DIECISEIS => true,
            self::CERO, self::EXENTO => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::DIECIOCHO => '18%',
            self::DIECISEIS => '16%',
            self::CERO => '0%',
            self::EXENTO => 'Exento',
        };
    }
}
