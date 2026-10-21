<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoComanda: string
{
    case ABIERTA = 'abierta';
    case EN_PREPARACION = 'en_preparacion';
    case LISTA = 'lista';
    case CERRADA = 'cerrada';
    case CANCELADA = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ABIERTA => 'Abierta',
            self::EN_PREPARACION => 'En preparación',
            self::LISTA => 'Lista para servir',
            self::CERRADA => 'Cerrada',
            self::CANCELADA => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ABIERTA => 'info',
            self::EN_PREPARACION => 'warning',
            self::LISTA => 'success',
            self::CERRADA => 'gray',
            self::CANCELADA => 'danger',
        };
    }
}
