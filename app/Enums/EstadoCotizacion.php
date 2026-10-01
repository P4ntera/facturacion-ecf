<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoCotizacion: string
{
    case BORRADOR  = 'borrador';
    case ENVIADA   = 'enviada';
    case APROBADA  = 'aprobada';
    case RECHAZADA = 'rechazada';
    case VENCIDA   = 'vencida';
    case FACTURADA = 'facturada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::BORRADOR  => 'Borrador',
            self::ENVIADA   => 'Enviada',
            self::APROBADA  => 'Aprobada',
            self::RECHAZADA => 'Rechazada',
            self::VENCIDA   => 'Vencida',
            self::FACTURADA => 'Facturada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BORRADOR  => 'gray',
            self::ENVIADA   => 'info',
            self::APROBADA  => 'success',
            self::RECHAZADA => 'danger',
            self::VENCIDA   => 'warning',
            self::FACTURADA => 'primary',
        };
    }

    public function puedeEditar(): bool
    {
        return $this === self::BORRADOR;
    }
}
