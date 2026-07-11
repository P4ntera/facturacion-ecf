<?php

namespace App\Enums;

/** Resultado de reenviar al PAC un documento recibido en nuestros endpoints públicos. */
enum EstadoReenvioPac: string
{
    case REENVIADO = 'reenviado';
    case ERROR_REENVIO = 'error_reenvio';
    case RECHAZADO_VALIDACION = 'rechazado_validacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::REENVIADO => 'Reenviado al PAC',
            self::ERROR_REENVIO => 'Error al reenviar',
            self::RECHAZADO_VALIDACION => 'Rechazado (validación)',
        };
    }
}
