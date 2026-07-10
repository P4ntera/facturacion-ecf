<?php

namespace App\Enums;

/** Nuestra decisión (como comprador) sobre un e-CF recibido de un proveedor. */
enum EstadoAprobacionComercial: string
{
    case PENDIENTE = 'pendiente';
    case ACEPTADO = 'aceptado';
    case RECHAZADO = 'rechazado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::ACEPTADO => 'Aceptado',
            self::RECHAZADO => 'Rechazado',
        };
    }
}
