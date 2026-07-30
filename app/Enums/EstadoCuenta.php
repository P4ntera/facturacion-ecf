<?php

namespace App\Enums;

/**
 * Estado de una CuentaPorCobrar/CuentaPorPagar. Nunca se persiste: se calcula en el momento
 * (CuentaPorCobrar::estado()/CuentaPorPagar::estado()) a partir de monto_total, monto_pagado y
 * fecha_vencimiento, para que nunca quede desactualizado (VENCIDA depende de la fecha de hoy, que
 * cambia sin que nadie toque el registro).
 */
enum EstadoCuenta: string
{
    case PENDIENTE = 'pendiente';
    case PARCIAL = 'parcial';
    case PAGADA = 'pagada';
    case VENCIDA = 'vencida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::PARCIAL => 'Parcial',
            self::PAGADA => 'Pagada',
            self::VENCIDA => 'Vencida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDIENTE => 'gray',
            self::PARCIAL => 'warning',
            self::PAGADA => 'success',
            self::VENCIDA => 'danger',
        };
    }
}
