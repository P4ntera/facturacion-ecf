<?php

namespace App\Enums;

enum TipoDocumentoCliente: string
{
    case RNC           = 'rnc';
    case CEDULA        = 'cedula';
    case SIN_DOCUMENTO = 'sin_documento';
}
