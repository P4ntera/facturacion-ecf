<?php

namespace App\Enums;

/** Qué pasa con un producto que el cliente devuelve. */
enum DestinoDevolucion: string
{
    /** En buen estado: vuelve al inventario y se puede vender otra vez. */
    case INVENTARIO = 'inventario';

    /** Dañado o vencido: entra y sale del Kardex como merma (pérdida), no vuelve a la venta. */
    case MERMA = 'merma';

    public function etiqueta(): string
    {
        return match ($this) {
            self::INVENTARIO => 'Vuelve al inventario',
            self::MERMA => 'Merma (dañado o vencido)',
        };
    }
}
