<?php

namespace App\Enums;

enum TipoProveedor: string
{
    case FORMAL   = 'formal';
    case INFORMAL = 'informal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FORMAL   => 'Formal (emite su propio comprobante)',
            self::INFORMAL => 'Informal (sin comprobante fiscal)',
        };
    }
}
