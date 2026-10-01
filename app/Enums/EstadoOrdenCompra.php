<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoOrdenCompra: string
{
    case BORRADOR          = 'borrador';
    case ENVIADA           = 'enviada';
    case RECEPCION_PARCIAL = 'recepcion_parcial';
    case COMPLETADA        = 'completada';
    case CANCELADA         = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::BORRADOR          => 'Borrador',
            self::ENVIADA           => 'Enviada',
            self::RECEPCION_PARCIAL => 'Recepción parcial',
            self::COMPLETADA        => 'Completada',
            self::CANCELADA         => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BORRADOR          => 'gray',
            self::ENVIADA           => 'info',
            self::RECEPCION_PARCIAL => 'warning',
            self::COMPLETADA        => 'success',
            self::CANCELADA         => 'danger',
        };
    }

    public function puedeRecibir(): bool
    {
        return in_array($this, [self::ENVIADA, self::RECEPCION_PARCIAL]);
    }

    public function puedeEditar(): bool
    {
        return $this === self::BORRADOR;
    }

    public function puedeCancelar(): bool
    {
        return in_array($this, [self::BORRADOR, self::ENVIADA]);
    }
}
