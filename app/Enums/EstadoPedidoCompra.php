<?php

namespace App\Enums;

enum EstadoPedidoCompra: string
{
    case PENDIENTE = 'pendiente';
    case CANCELADO = 'cancelado';
}
