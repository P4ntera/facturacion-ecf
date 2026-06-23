<?php

namespace App\Enums;

enum TipoMovimiento: string
{
    case ENTRADA = 'ENTRADA';
    case SALIDA  = 'SALIDA';
    case AJUSTE  = 'AJUSTE';
}
