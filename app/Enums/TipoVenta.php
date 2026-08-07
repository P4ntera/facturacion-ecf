<?php

namespace App\Enums;

enum TipoVenta: string
{
    case CONTABLE = 'contable';
    case PESADO = 'pesado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::CONTABLE => 'Contable (unidades)',
            self::PESADO => 'Pesado (por peso)',
        };
    }
}
