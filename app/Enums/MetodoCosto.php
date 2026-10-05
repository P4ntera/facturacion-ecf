<?php

namespace App\Enums;

/**
 * Cómo se actualiza Producto::costo cuando entra mercancía con costo conocido (compra o recepción
 * de orden de compra). Se elige por empresa en Configuración → Facturación: mezclar métodos dentro
 * de una misma empresa haría inexplicable el valor del inventario.
 */
enum MetodoCosto: string
{
    /** El usuario mantiene el costo a mano; las compras no lo tocan. */
    case MANUAL = 'manual';

    /** El costo pasa a ser el de la compra más reciente (comportamiento histórico del sistema). */
    case ULTIMA_COMPRA = 'ultima_compra';

    /** Mezcla lo que había en almacén con lo que entra: (stock × costo + cantidad × costo compra) ÷ stock nuevo. */
    case PROMEDIO_PONDERADO = 'promedio_ponderado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::ULTIMA_COMPRA => 'Última compra',
            self::PROMEDIO_PONDERADO => 'Promedio ponderado',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::MANUAL => 'El costo lo pones tú en cada producto. Las compras no lo cambian.',
            self::ULTIMA_COMPRA => 'El costo pasa a ser el de la compra más reciente.',
            self::PROMEDIO_PONDERADO => 'Mezcla el costo de lo que ya tenías con el de lo que entra. Es el que refleja lo que de verdad pagaste por tu inventario.',
        };
    }
}
