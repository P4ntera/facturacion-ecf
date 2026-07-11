<?php

namespace App\Enums;

/** Cuál de las dos URLs registradas en la DGII recibió el documento. */
enum CanalRecepcionEcf: string
{
    case RECEPCION = 'recepcion';
    case APROBACION_COMERCIAL = 'aprobacion_comercial';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RECEPCION => 'Recepción',
            self::APROBACION_COMERCIAL => 'Aprobación comercial',
        };
    }

    /** Segmento de la URL del PAC al reenviar: {base}/{rnc}/fe/{segmento}/api/ecf. */
    public function segmentoPac(): string
    {
        return match ($this) {
            self::RECEPCION => 'recepcion',
            self::APROBACION_COMERCIAL => 'aprobacioncomercial',
        };
    }
}
