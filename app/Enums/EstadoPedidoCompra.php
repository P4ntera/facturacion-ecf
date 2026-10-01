<?php

namespace App\Enums;

enum EstadoPedidoCompra: string
{
    case PENDIENTE = 'pendiente';
    case RECIBIDO = 'recibido';
    case CANCELADO = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::RECIBIDO => 'Recibido',
            self::CANCELADO => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDIENTE => 'gray',
            self::RECIBIDO => 'success',
            self::CANCELADO => 'danger',
        };
    }
}
