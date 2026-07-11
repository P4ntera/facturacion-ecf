<?php

namespace App\Enums;

enum AmbienteEcf: string
{
    case TESTECF = 'TesteCF';
    case CERTECF = 'CerteCF';
    case ECF = 'eCF';

    public function etiqueta(): string
    {
        return match ($this) {
            self::TESTECF => 'Pruebas',
            self::CERTECF => 'Certificación',
            self::ECF => 'Producción',
        };
    }

    public function esProduccion(): bool
    {
        return $this === self::ECF;
    }
}
