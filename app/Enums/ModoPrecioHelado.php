<?php

declare(strict_types=1);

namespace App\Enums;

enum ModoPrecioHelado: string
{
    case POR_PRESENTACION = 'por_presentacion';
    case POR_SCOOP = 'por_scoop';

    public function etiqueta(): string
    {
        return match ($this) {
            self::POR_PRESENTACION => 'Por presentación (precio fijo)',
            self::POR_SCOOP => 'Por scoop (precio × cantidad)',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::POR_PRESENTACION => 'El cono/vasito tiene un precio fijo sin importar cuántos scoops tenga',
            self::POR_SCOOP => 'Se cobra cada scoop individual, el precio final depende de cuántos elija el cliente',
        };
    }
}
