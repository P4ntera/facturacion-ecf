<?php

namespace App\Enums;

enum OrigenMovimiento: string
{
    case VENTA             = 'venta';
    case COMPRA            = 'compra';
    case AJUSTE            = 'ajuste';
    case ANULACION         = 'anulacion';
    case DEVOLUCION_COMPRA = 'devolucion_compra';
    case DEVOLUCION_VENTA      = 'devolucion_venta';
    case RECEPCION_ORDEN_COMPRA = 'recepcion_orden_compra';
    /** Producto devuelto por un cliente que llegó dañado o vencido: sale del inventario como pérdida. */
    case MERMA = 'merma';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VENTA => 'Venta',
            self::COMPRA => 'Compra',
            self::AJUSTE => 'Ajuste',
            self::ANULACION => 'Anulación',
            self::DEVOLUCION_COMPRA => 'Devolución a proveedor',
            self::DEVOLUCION_VENTA => 'Devolución de cliente',
            self::RECEPCION_ORDEN_COMPRA => 'Recepción de orden de compra',
            self::MERMA => 'Merma',
        };
    }
}
