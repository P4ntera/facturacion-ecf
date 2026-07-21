<?php

namespace App\Enums;

enum EstadoArqueoCaja: string
{
    case ABIERTO = 'abierto';
    case CERRADO = 'cerrado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ABIERTO => 'Abierto',
            self::CERRADO => 'Cerrado',
        };
    }
}
