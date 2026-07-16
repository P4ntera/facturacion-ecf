<?php

namespace App\Enums;

enum ModuloImpresion: string
{
    case FACTURACION = 'facturacion';
    case REPORTES = 'reportes';
    case COCINA = 'cocina';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FACTURACION => 'Facturación',
            self::REPORTES => 'Reportes',
            self::COCINA => 'Cocina',
        };
    }
}
