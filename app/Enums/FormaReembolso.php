<?php

namespace App\Enums;

/**
 * Cómo se le devuelve el dinero al cliente en una devolución. Cada empresa elige cuáles acepta
 * (Configuración → Facturación → Devoluciones). Si la venta fue a crédito, primero se rebaja lo
 * que el cliente debe en su cuenta por cobrar, y solo el resto se reembolsa por uno de estos.
 */
enum FormaReembolso: string
{
    /** Sale de la caja de hoy: resta del efectivo esperado en el cierre. */
    case EFECTIVO = 'efectivo';

    /** Por el mismo medio del pago original (reverso de tarjeta o transferencia): no toca la caja. */
    case MISMO_MEDIO = 'mismo_medio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EFECTIVO => 'Efectivo (sale de la caja de hoy)',
            self::MISMO_MEDIO => 'Por el mismo medio del pago (tarjeta o transferencia)',
        };
    }
}
