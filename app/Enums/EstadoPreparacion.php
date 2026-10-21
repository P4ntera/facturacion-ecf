<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoPreparacion: string
{
    case PENDIENTE = 'pendiente';
    case EN_PREPARACION = 'en_preparacion';
    case LISTO = 'listo';
    case ENTREGADO = 'entregado';
    case CANCELADO = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::EN_PREPARACION => 'En preparación',
            self::LISTO => 'Listo',
            self::ENTREGADO => 'Entregado',
            self::CANCELADO => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDIENTE => 'gray',
            self::EN_PREPARACION => 'warning',
            self::LISTO => 'success',
            self::ENTREGADO => 'info',
            self::CANCELADO => 'danger',
        };
    }
}
