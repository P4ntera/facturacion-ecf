<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoPagoTicket: string
{
    case PENDIENTE = 'pendiente';
    case PAGADO = 'pagado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::PAGADO => 'Pagado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDIENTE => 'warning',
            self::PAGADO => 'success',
        };
    }
}
