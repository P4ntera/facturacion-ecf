<?php

namespace App\Enums;

enum EstadoDevolucion: string
{
    case REGISTRADA = 'registrada';
    case ANULADA    = 'anulada';
}
