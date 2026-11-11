<?php

namespace App\Enums;

enum FormaPago: string
{
    case EFECTIVO      = 'efectivo';
    case TARJETA       = 'tarjeta';
    case TRANSFERENCIA = 'transferencia';
    case MIXTO         = 'mixto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EFECTIVO      => 'Efectivo',
            self::TARJETA       => 'Tarjeta',
            self::TRANSFERENCIA => 'Transferencia',
            self::MIXTO         => 'Mixto (Efectivo + Tarjeta)',
        };
    }

    /** true si esta forma de pago incluye componente en efectivo (para cálculo de vuelto). */
    public function incluyeEfectivo(): bool
    {
        return match ($this) {
            self::EFECTIVO, self::MIXTO => true,
            default => false,
        };
    }

    /**
     * Código DGII para el formato e-CF (Tabla "Formas de Pago"): 1 efectivo,
     * 2 cheque/transferencia/depósito, 3 tarjeta débito/crédito, 4 venta a crédito,
     * 5 bonos/certificados, 6 permuta, 7 nota de crédito, 8 otras.
     */
    public function codigoDgii(): string
    {
        return match ($this) {
            self::EFECTIVO      => '1',
            self::TARJETA       => '3',
            self::TRANSFERENCIA => '2',
            self::MIXTO         => '1',
        };
    }
}
