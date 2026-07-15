<?php

namespace App\Enums;

enum AnchoPapel: string
{
    case MM80 = '80';
    case MM58 = '58';

    public function etiqueta(): string
    {
        return match ($this) {
            self::MM80 => '80mm',
            self::MM58 => '58mm',
        };
    }

    /** Columnas de texto aproximadas para una fuente monoespaciada de ticket. */
    public function columnas(): int
    {
        return match ($this) {
            self::MM80 => 48,
            self::MM58 => 32,
        };
    }
}
