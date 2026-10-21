<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoMesa: string
{
    case DISPONIBLE = 'disponible';
    case OCUPADA = 'ocupada';
    case RESERVADA = 'reservada';
    case FUERA_DE_SERVICIO = 'fuera_de_servicio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::OCUPADA => 'Ocupada',
            self::RESERVADA => 'Reservada',
            self::FUERA_DE_SERVICIO => 'Fuera de servicio',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'success',
            self::OCUPADA => 'danger',
            self::RESERVADA => 'warning',
            self::FUERA_DE_SERVICIO => 'gray',
        };
    }
}
