<?php

namespace App\Enums;

enum OrigenMovimiento: string
{
    case VENTA     = 'venta';
    case COMPRA    = 'compra';
    case AJUSTE    = 'ajuste';
    case ANULACION = 'anulacion';
}
