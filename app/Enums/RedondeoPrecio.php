<?php

namespace App\Enums;

/**
 * Redondeo del precio sugerido (costo + porcentaje de ganancia). Siempre hacia ARRIBA al múltiplo
 * elegido, para no comerse el margen: RD$63.40 con "a 5 pesos" → RD$65.
 */
enum RedondeoPrecio: string
{
    case NINGUNO = 'ninguno';
    case UNIDAD = '1';
    case CINCO = '5';
    case DIEZ = '10';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NINGUNO => 'Sin redondeo (con centavos)',
            self::UNIDAD => 'Al peso (RD$63.40 → RD$64)',
            self::CINCO => 'A 5 pesos (RD$63.40 → RD$65)',
            self::DIEZ => 'A 10 pesos (RD$63.40 → RD$70)',
        };
    }

    public function multiplo(): ?int
    {
        return match ($this) {
            self::NINGUNO => null,
            self::UNIDAD => 1,
            self::CINCO => 5,
            self::DIEZ => 10,
        };
    }
}
