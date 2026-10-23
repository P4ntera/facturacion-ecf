<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoTicketLavanderia: string
{
    case RECIBIDO = 'recibido';
    case EN_PROCESO = 'en_proceso';
    case LISTO = 'listo';
    case ENTREGADO = 'entregado';
    case CANCELADO = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RECIBIDO => 'Recibido',
            self::EN_PROCESO => 'En proceso',
            self::LISTO => 'Listo para entrega',
            self::ENTREGADO => 'Entregado',
            self::CANCELADO => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::RECIBIDO => 'info',
            self::EN_PROCESO => 'warning',
            self::LISTO => 'success',
            self::ENTREGADO => 'gray',
            self::CANCELADO => 'danger',
        };
    }
}
