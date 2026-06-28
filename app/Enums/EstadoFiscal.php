<?php

namespace App\Enums;

enum EstadoFiscal: string
{
    case NO_APLICA            = 'no_aplica';
    case PENDIENTE            = 'pendiente';
    case EN_PROCESO           = 'en_proceso';
    case ACEPTADO             = 'aceptado';
    case ACEPTADO_CONDICIONAL = 'aceptado_condicional';
    case RECHAZADO            = 'rechazado';
}
