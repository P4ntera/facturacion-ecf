<?php

namespace App\Enums;

enum TipoMovimiento: string
{
    case ENTRADA = 'entrada';
    case SALIDA  = 'salida';
    case AJUSTE  = 'ajuste';
}
