<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoPromocion: string
{
    case PORCENTAJE = 'porcentaje';
    case MONTO_FIJO = 'monto_fijo';
    case DOS_POR_UNO = '2x1';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PORCENTAJE => 'Descuento porcentual',
            self::MONTO_FIJO => 'Descuento monto fijo',
            self::DOS_POR_UNO => '2×1 (paga 1, lleva 2)',
        };
    }

    /**
     * ¿El campo "valor" aplica para este tipo de promoción?
     * En 2×1 no se necesita un valor numérico: el descuento es el precio de la unidad gratis.
     */
    public function requiereValor(): bool
    {
        return match ($this) {
            self::DOS_POR_UNO => false,
            default => true,
        };
    }
}
