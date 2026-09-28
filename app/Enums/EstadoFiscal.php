<?php

namespace App\Enums;

enum EstadoFiscal: string
{
    case NO_APLICA = 'no_aplica';
    case PENDIENTE = 'pendiente';
    case EN_PROCESO = 'en_proceso';
    case ACEPTADO = 'aceptado';
    case ACEPTADO_CONDICIONAL = 'aceptado_condicional';
    case RECHAZADO = 'rechazado';

    // El PAC convierte automáticamente a RFCE (Régimen de Factura de Consumo Electrónica) los
    // e-CF 32 por debajo de Venta::UMBRAL_CONSUMO: es un estado final de aceptación, no un error.
    case RFCE = 'rfce';

    /** La DGII tiene el e-CF como válido: anularlo exige emitir una Nota de Crédito (e-CF 34). */
    public function esAceptado(): bool
    {
        return in_array($this, [self::ACEPTADO, self::ACEPTADO_CONDICIONAL, self::RFCE], true);
    }

    /** Todavía no hay respuesta final de la DGII (enviado o por enviar). */
    public function estaEnTramite(): bool
    {
        return $this === self::PENDIENTE || $this === self::EN_PROCESO;
    }
}
