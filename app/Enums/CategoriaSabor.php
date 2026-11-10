<?php

declare(strict_types=1);

namespace App\Enums;

enum CategoriaSabor: string
{
    case CREMOSO = 'cremoso';
    case FRUTAL = 'frutal';
    case ESPECIAL = 'especial';

    public function etiqueta(): string
    {
        return match ($this) {
            self::CREMOSO => 'Cremoso',
            self::FRUTAL => 'Frutal',
            self::ESPECIAL => 'Especial',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CREMOSO => 'warning',
            self::FRUTAL => 'success',
            self::ESPECIAL => 'info',
        };
    }
}
