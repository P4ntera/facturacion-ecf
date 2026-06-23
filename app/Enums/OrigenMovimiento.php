<?php

namespace App\Enums;

enum OrigenMovimiento: string
{
    case VENTA     = 'VENTA';
    case COMPRA    = 'COMPRA';
    case AJUSTE    = 'AJUSTE';
    case ANULACION = 'ANULACION';
}
